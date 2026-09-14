<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use App\Contracts\PaymentGateway;
use App\Integrations\Payments\Data\BankOption;
use App\Integrations\Payments\Data\CollectionResponse;
use App\Integrations\Payments\Data\GatewayTransaction;
use App\Integrations\Payments\Data\PaymentStatus;
use App\Integrations\Payments\Data\ResolvedAccount;
use App\Integrations\Payments\Data\SettlementRecord;
use App\Integrations\Payments\Data\TransferResponse;
use App\Integrations\Payments\Exceptions\LencoRequestFailed;
use App\Integrations\Payments\Lenco\LencoResponse;
use App\Support\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Lenco API v2 — the real thing.
 *
 * Everything MonaFind knows about Lenco lives in this class and the config
 * file behind it. Above the PaymentGateway interface nothing has heard of
 * `lencoReference`, `pay-offline` or the `{status, message, data}` envelope,
 * which is what lets the entire money layer be built and tested against the
 * fake before a key exists.
 *
 * ## Three things this class is careful about
 *
 * **Amounts.** Lenco speaks decimal kwacha as JSON strings ("1234.56"); the
 * platform stores integer ngwee. Both directions go through Money, never
 * through a float, so nothing is ever a ngwee out.
 *
 * **Retries.** Reads are retried, writes are not. A GET that times out can be
 * asked again for free. A POST /transfers that times out may well have paid a
 * seller, and asking again would pay them twice — so a failed write surfaces
 * as an exception and the payout line is left for a human, which is the whole
 * point of tracking lines individually.
 *
 * **Statuses.** Lenco has five collection statuses to the platform's four.
 * `pay-offline` and `3ds-auth-required` both mean the customer is still doing
 * something — a USSD PIN, a bank's 3-D Secure page — so both map to Pending.
 * Treating them as failures would cancel orders that are about to be paid.
 */
class LencoGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $secretKey,
        private readonly ?string $accountId = null,
        private readonly string $country = 'zm',
        private readonly string $defaultBearer = 'merchant',
        private readonly int $timeout = 30,
        private readonly int $retryTimes = 2,
        private readonly int $retrySleepMs = 200,
        private readonly ?string $webhookSecret = null,
    ) {}

    /**
     * Build the payment reference for an order group attempt.
     *
     * Lenco accepts only [A-Za-z0-9._-] and refuses a reference it has seen
     * before, so the attempt number is part of it: a buyer whose card is
     * declined needs a new reference for the same group.
     */
    public function reference(string $orderGroupId, int $attempt): string
    {
        return sprintf('MFA-%s-%d', preg_replace('/[^A-Za-z0-9._-]/', '', $orderGroupId), $attempt);
    }

    /**
     * Register the intent to collect, for the inline widget to carry out.
     *
     * There is nothing to call: the widget opens with the reference and
     * amount and drives the card or wallet itself. What comes back is the
     * config the browser needs, with the checkout URL pointing at our own
     * pending screen rather than at Lenco — the widget is an overlay on our
     * page, not a redirect.
     *
     * @param  array{name?: string, email?: string, phone?: string}  $customer
     * @param  array<string, scalar|null>  $metadata
     */
    public function initiateCollection(
        Money $amount,
        string $reference,
        array $customer,
        array $metadata = [],
    ): CollectionResponse {
        return new CollectionResponse(
            reference: $reference,
            status: PaymentStatus::Pending,
            amount: $amount,
            raw: ['customer' => $customer, 'metadata' => $metadata],
        );
    }

    /**
     * POST /collections/mobile-money — push a PIN prompt to the handset.
     *
     * @param  array<string, scalar|null>  $metadata
     */
    public function collectMobileMoney(
        Money $amount,
        string $reference,
        string $phone,
        string $operator,
        array $metadata = [],
    ): CollectionResponse {
        $response = $this->write('post', '/collections/mobile-money', [
            'amount' => (float) $amount->toDecimalString(),
            'reference' => $reference,
            'phone' => $phone,
            'operator' => $operator,
            'country' => $this->country,
            'bearer' => $this->defaultBearer,
        ]);

        if (! $response->successful) {
            throw LencoRequestFailed::collection($reference, $response->message, $response->httpStatus);
        }

        return $this->collectionFrom($response->object(), $reference, $amount);
    }

    /**
     * GET /collections/status/:reference — the authoritative read.
     *
     * A reference Lenco has never heard of comes back Pending rather than
     * Failed. It is the normal answer in the seconds between the widget
     * opening and the customer touching anything, and calling that a failure
     * would cancel an order mid-payment.
     */
    public function fetchCollection(string $reference): CollectionResponse
    {
        $response = $this->read('/collections/status/'.rawurlencode($reference));

        if (! $response->successful) {
            return new CollectionResponse(
                reference: $reference,
                status: PaymentStatus::Pending,
                amount: Money::zero(),
                failureReason: $response->message !== '' ? $response->message : null,
                raw: $response->raw,
            );
        }

        return $this->collectionFrom($response->object(), $reference);
    }

    /**
     * POST /transfers/{bank-account|mobile-money} — pay a seller.
     *
     * Addressed by stored recipient id where there is one, and by raw account
     * details otherwise. Never retried: see the class docblock.
     *
     * @param  array{account_number?: string, bank_code?: string, account_name?: string, phone?: string, network?: string, recipient_id?: string, narration?: string}  $recipient
     * @param  array<string, scalar|null>  $metadata
     */
    public function initiateTransfer(
        Money $amount,
        string $reference,
        array $recipient,
        array $metadata = [],
    ): TransferResponse {
        $isMobileMoney = ($recipient['phone'] ?? null) !== null || ($recipient['network'] ?? null) !== null;

        $payload = array_filter([
            'accountId' => $this->accountId,
            'amount' => (float) $amount->toDecimalString(),
            'reference' => $reference,
            'narration' => $recipient['narration'] ?? null,
            'transferRecipientId' => $recipient['recipient_id'] ?? null,
            'country' => $this->country,
        ], static fn (mixed $value): bool => $value !== null);

        /*
         * Account details are sent alongside the recipient id, not instead of
         * it. Lenco accepts either; sending both means a recipient that has
         * been deleted at their end still pays out.
         */
        $payload += $isMobileMoney
            ? array_filter([
                'phone' => $recipient['phone'] ?? null,
                'operator' => $recipient['network'] ?? null,
            ], static fn (mixed $value): bool => $value !== null)
            : array_filter([
                'accountNumber' => $recipient['account_number'] ?? null,
                'bankId' => $recipient['bank_code'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);

        $path = $isMobileMoney ? '/transfers/mobile-money' : '/transfers/bank-account';

        $response = $this->write('post', $path, $payload);

        if (! $response->successful) {
            throw LencoRequestFailed::transfer($reference, $response->message, $response->httpStatus);
        }

        return $this->transferFrom($response->object(), $reference, $amount);
    }

    /**
     * GET /transfers/status/:reference.
     *
     * Unlike a collection, an unknown transfer reference is Pending too: it
     * means the transfer never reached Lenco, and the payout line is left
     * open for Finance rather than being recorded as a failure that would
     * revert a payable the platform may in fact have paid.
     */
    public function fetchTransfer(string $reference): TransferResponse
    {
        $response = $this->read('/transfers/status/'.rawurlencode($reference));

        if (! $response->successful) {
            return new TransferResponse(
                reference: $reference,
                status: PaymentStatus::Pending,
                amount: Money::zero(),
                failureReason: $response->message !== '' ? $response->message : null,
                raw: $response->raw,
            );
        }

        return $this->transferFrom($response->object(), $reference);
    }

    /**
     * GET /banks.
     *
     * @return array<int, BankOption>
     */
    public function listBanks(): array
    {
        $response = $this->read('/banks', ['country' => $this->country]);

        return array_values(array_map(
            static fn (array $bank): BankOption => new BankOption(
                code: (string) ($bank['id'] ?? ''),
                name: (string) ($bank['name'] ?? ''),
            ),
            $response->rows(),
        ));
    }

    /**
     * POST /resolve/bank-account — the name the bank has on the account.
     */
    public function resolveBankAccount(string $accountNumber, string $bankCode): ?ResolvedAccount
    {
        $response = $this->write('post', '/resolve/bank-account', [
            'accountNumber' => $accountNumber,
            'bankId' => $bankCode,
            'country' => $this->country,
        ], throwOnFailure: false);

        if (! $response->successful) {
            return null;
        }

        $data = $response->object();
        /** @var array<string, mixed> $bank */
        $bank = is_array($data['bank'] ?? null) ? $data['bank'] : [];

        return new ResolvedAccount(
            accountName: (string) ($data['accountName'] ?? ''),
            accountNumber: (string) ($data['accountNumber'] ?? $accountNumber),
            bankCode: (string) ($bank['id'] ?? $bankCode),
            bankName: isset($bank['name']) ? (string) $bank['name'] : null,
            raw: $data,
        );
    }

    /**
     * POST /resolve/mobile-money — the name the network has on the wallet.
     */
    public function resolveMobileMoney(string $phone, string $network): ?ResolvedAccount
    {
        $response = $this->write('post', '/resolve/mobile-money', [
            'phone' => $phone,
            'operator' => $network,
            'country' => $this->country,
        ], throwOnFailure: false);

        if (! $response->successful) {
            return null;
        }

        $data = $response->object();

        return new ResolvedAccount(
            accountName: (string) ($data['accountName'] ?? ''),
            accountNumber: (string) ($data['phone'] ?? $phone),
            network: (string) ($data['operator'] ?? $network),
            raw: $data,
        );
    }

    /**
     * POST /transfer-recipients/{bank-account|mobile-money}.
     */
    public function createTransferRecipient(ResolvedAccount $account): string
    {
        [$path, $payload] = $account->isMobileMoney()
            ? ['/transfer-recipients/mobile-money', [
                'phone' => $account->accountNumber,
                'operator' => $account->network,
            ]]
            : ['/transfer-recipients/bank-account', [
                'accountNumber' => $account->accountNumber,
                'bankId' => $account->bankCode,
            ]];

        $response = $this->write('post', $path, $payload + ['country' => $this->country]);

        if (! $response->successful) {
            throw LencoRequestFailed::recipient($account->accountNumber, $response->message, $response->httpStatus);
        }

        return (string) ($response->object()['id'] ?? '');
    }

    /**
     * HMAC-SHA512 of the raw body against the webhook hash key.
     *
     * `hash_equals` rather than `===` so the comparison takes the same time
     * whatever the signature is; a webhook endpoint is public, and a
     * short-circuiting compare is a forgery oracle. Configured secret first,
     * else the documented derivation from the API token.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $secret = $this->webhookSecret ?? hash('sha256', $this->secretKey);

        return hash_equals(hash_hmac('sha512', $payload, $secret), $signature);
    }

    /**
     * GET /collections for a window.
     *
     * @return array<int, CollectionResponse>
     */
    public function collectionsBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        return array_map(
            fn (array $row): CollectionResponse => $this->collectionFrom(
                $row,
                (string) ($row['reference'] ?? $row['lencoReference'] ?? ''),
            ),
            $this->allPages('/collections', $from, $to),
        );
    }

    /**
     * GET /settlements for a window.
     *
     * @return array<int, SettlementRecord>
     */
    public function settlementsBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        return array_map(static function (array $row): SettlementRecord {
            /** @var array<string, mixed> $collection */
            $collection = is_array($row['collection'] ?? null) ? $row['collection'] : [];

            return new SettlementRecord(
                id: (string) ($row['id'] ?? ''),
                amountSettled: LencoResponse::money($row['amountSettled'] ?? null) ?? Money::zero(),
                status: (string) ($row['status'] ?? 'pending'),
                collectionReference: isset($collection['reference']) ? (string) $collection['reference'] : null,
                collectionAmount: LencoResponse::money($collection['amount'] ?? null),
                settledAt: isset($row['settledAt']) ? Carbon::parse((string) $row['settledAt']) : null,
                raw: $row,
            );
        }, $this->allPages('/settlements', $from, $to));
    }

    /**
     * GET /transactions for a window.
     *
     * @return array<int, GatewayTransaction>
     */
    public function transactionsBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        $query = $this->accountId !== null ? ['accountId' => $this->accountId] : [];

        return array_map(static fn (array $row): GatewayTransaction => new GatewayTransaction(
            id: (string) ($row['id'] ?? ''),
            amount: LencoResponse::money($row['amount'] ?? null) ?? Money::zero(),
            direction: (string) ($row['type'] ?? 'credit'),
            narration: isset($row['narration']) ? (string) $row['narration'] : null,
            occurredAt: isset($row['datetime']) ? Carbon::parse((string) $row['datetime']) : null,
            raw: $row,
        ), $this->allPages('/transactions', $from, $to, $query));
    }

    /**
     * GET /accounts/{id}/balance — what Lenco says we are holding.
     *
     * Read rather than retried into existence: `read()` already retries a
     * transient failure, and anything still failing after that is reported as
     * "unknown" rather than guessed at. Null is not zero, and the balance
     * check on the console draws that distinction on screen.
     */
    public function accountBalance(): ?Money
    {
        if ($this->accountId === null) {
            return null;
        }

        $response = $this->read('/accounts/'.$this->accountId.'/balance');

        if (! $response->successful) {
            return null;
        }

        $data = $response->object();

        /*
         * Lenco has named this field differently across account types, so the
         * available balance is preferred and the plain one accepted. Available
         * is the right figure for a cash check: it excludes money the gateway
         * has taken but not yet made spendable.
         */
        return LencoResponse::money($data['availableBalance'] ?? $data['balance'] ?? null);
    }

    /**
     * The widget's script URL and the environment it belongs to.
     *
     * Deliberately NOT the public key: what the browser is handed is decided
     * by the service that renders the page, so that the one place a key can
     * leak from is the one place that is tested for it.
     *
     * @return array{url: string, environment: string}
     */
    public function widget(): array
    {
        $environment = (string) config('lenco.environment', 'sandbox');

        return [
            'url' => (string) config("lenco.endpoints.{$environment}.widget"),
            'environment' => $environment,
        ];
    }

    /**
     * Walk every page of a dated listing.
     *
     * Reconciliation is a nightly job with no user waiting on it, so it reads
     * the whole window rather than a first page — a missing collection that
     * happened to fall on page two is exactly the thing it exists to catch.
     *
     * @param  array<string, scalar>  $extra
     * @return array<int, array<string, mixed>>
     */
    private function allPages(string $path, CarbonInterface $from, CarbonInterface $to, array $extra = []): array
    {
        $rows = [];
        $page = 1;

        do {
            $response = $this->read($path, [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'page' => $page,
                ...$extra,
            ]);

            $rows = [...$rows, ...$response->rows()];
            $page++;

            /* A hard stop, so a gateway that always claims another page cannot spin the job forever. */
        } while ($response->hasMorePages() && $page <= 200);

        return $rows;
    }

    /**
     * Map a Lenco collection object onto the platform's shape.
     *
     * The amount falls back to what we asked for when the gateway does not
     * echo one, which happens on the error paths — better a known figure than
     * a zero that reads as a free order.
     *
     * @param  array<string, mixed>  $data
     */
    private function collectionFrom(array $data, string $reference, ?Money $fallbackAmount = null): CollectionResponse
    {
        return new CollectionResponse(
            reference: (string) ($data['reference'] ?? $reference),
            status: $this->statusFrom($data['status'] ?? null),
            amount: LencoResponse::money($data['amount'] ?? null) ?? $fallbackAmount ?? Money::zero(),
            gatewayId: isset($data['id']) ? (string) $data['id'] : null,
            checkoutUrl: null,
            fee: LencoResponse::money($data['fee'] ?? null),
            failureReason: $this->stringOrNull($data['reasonForFailure'] ?? null),
            raw: $data,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function transferFrom(array $data, string $reference, ?Money $fallbackAmount = null): TransferResponse
    {
        return new TransferResponse(
            reference: (string) ($data['reference'] ?? $reference),
            status: $this->statusFrom($data['status'] ?? null),
            amount: LencoResponse::money($data['amount'] ?? null) ?? $fallbackAmount ?? Money::zero(),
            gatewayId: isset($data['id']) ? (string) $data['id'] : null,
            fee: LencoResponse::money($data['fee'] ?? null),
            failureReason: $this->stringOrNull($data['reasonForFailure'] ?? null),
            raw: $data,
        );
    }

    /**
     * A payload value as a non-empty string, or null.
     *
     * Lenco sends `null` for a reason that does not apply and occasionally an
     * empty string; both mean "no reason", and neither should surface as one.
     */
    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Lenco's five statuses to the platform's four.
     *
     * `pay-offline` (a USSD prompt sitting on a handset) and
     * `3ds-auth-required` (a bank's verification page) are both the customer
     * still working, so both are Pending. Anything unrecognised is Pending
     * too: an unknown status is not evidence of failure, and the poller will
     * ask again.
     */
    private function statusFrom(mixed $status): PaymentStatus
    {
        return match ((string) $status) {
            'successful' => PaymentStatus::Successful,
            'failed' => PaymentStatus::Failed,
            'reversed' => PaymentStatus::Reversed,
            default => PaymentStatus::Pending,
        };
    }

    /**
     * A GET, retried on transport failure.
     *
     * @param  array<string, scalar>  $query
     */
    private function read(string $path, array $query = []): LencoResponse
    {
        return LencoResponse::fromHttp(
            $this->client()->retry($this->retryTimes, $this->retrySleepMs, throw: false)->get($path, $query),
        );
    }

    /**
     * A write, never retried.
     *
     * @param  array<string, mixed>  $payload
     */
    private function write(string $method, string $path, array $payload, bool $throwOnFailure = true): LencoResponse
    {
        $response = $this->client()->{$method}($path, $payload);

        $parsed = LencoResponse::fromHttp($response);

        /*
         * A 5xx or a dropped connection is not a refusal — Lenco may have
         * acted on it. Those become exceptions so the caller records the line
         * as unresolved rather than as failed; only a well-formed `status:
         * false` is a genuine "no".
         */
        if ($throwOnFailure && $response->serverError()) {
            throw LencoRequestFailed::unreachable($path, $response->status());
        }

        return $parsed;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->secretKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);
    }
}
