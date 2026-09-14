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
use App\Integrations\Support\RecordsCalls;
use App\Support\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * An in-memory payment gateway for tests and local development.
 *
 * Attempts start Pending and are settled by calling settle()/fail(), which is
 * how a test stands in for the webhook the real gateway would send.
 */
class FakePaymentGateway implements PaymentGateway
{
    use RecordsCalls;

    /** @var array<string, CollectionResponse> */
    private array $collections = [];

    /** @var array<string, TransferResponse> */
    private array $transfers = [];

    private PaymentStatus $nextCollectionStatus = PaymentStatus::Pending;

    private PaymentStatus $nextTransferStatus = PaymentStatus::Pending;

    /** @var array<int, SettlementRecord> */
    private array $settlements = [];

    /** @var array<int, GatewayTransaction> */
    private array $transactions = [];

    /** What the gateway claims to be holding. Null means it cannot say. */
    private ?Money $accountBalance = null;

    /** Account numbers and phone numbers the gateway will refuse to resolve. */
    private bool $resolutionSucceeds = true;

    /** @var array<string, string> Account or phone number => the name behind it. */
    private array $accountNames = [];

    private string $webhookSecret = 'fake-webhook-secret';

    public function reference(string $orderGroupId, int $attempt): string
    {
        return sprintf('MFA-%s-%d', preg_replace('/[^A-Za-z0-9._-]/', '', $orderGroupId), $attempt);
    }

    /**
     * @param  array{name?: string, email?: string, phone?: string}  $customer
     * @param  array<string, scalar|null>  $metadata
     */
    public function initiateCollection(Money $amount, string $reference, array $customer, array $metadata = []): CollectionResponse
    {
        $this->recordCall(__FUNCTION__, [
            'amount' => $amount->ngwee,
            'reference' => $reference,
            'customer' => $customer,
            'metadata' => $metadata,
        ]);

        return $this->collections[$reference] = new CollectionResponse(
            reference: $reference,
            status: $this->nextCollectionStatus,
            amount: $amount,
            gatewayId: 'fake_col_'.Str::lower(Str::random(16)),
            checkoutUrl: 'https://pay.fake.test/checkout/'.$reference,
        );
    }

    /**
     * A USSD push. Always lands Pending — the customer still has to key in a
     * PIN — which is what makes it the right shape for testing the poller.
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
        $this->recordCall(__FUNCTION__, [
            'amount' => $amount->ngwee,
            'reference' => $reference,
            'phone' => $phone,
            'operator' => $operator,
            'metadata' => $metadata,
        ]);

        return $this->collections[$reference] = new CollectionResponse(
            reference: $reference,
            status: $this->nextCollectionStatus,
            amount: $amount,
            gatewayId: 'fake_col_'.Str::lower(Str::random(16)),
        );
    }

    /**
     * A reference the fake has never seen comes back PENDING, not failed —
     * mirroring LencoGateway exactly.
     *
     * It is the normal answer in the seconds between a widget opening and the
     * customer touching anything, and it is also what a collection started by
     * the widget rather than by an API call looks like from the server's side.
     * A fake that called it a failure would let code pass here that cancels
     * orders mid-payment against the real gateway.
     */
    public function fetchCollection(string $reference): CollectionResponse
    {
        $this->recordCall(__FUNCTION__, ['reference' => $reference]);

        return $this->collections[$reference] ?? new CollectionResponse(
            reference: $reference,
            status: PaymentStatus::Pending,
            amount: Money::zero(),
        );
    }

    /**
     * @param  array{account_number?: string, bank_code?: string, account_name?: string, phone?: string}  $recipient
     * @param  array<string, scalar|null>  $metadata
     */
    public function initiateTransfer(Money $amount, string $reference, array $recipient, array $metadata = []): TransferResponse
    {
        $this->recordCall(__FUNCTION__, [
            'amount' => $amount->ngwee,
            'reference' => $reference,
            'recipient' => $recipient,
            'metadata' => $metadata,
        ]);

        return $this->transfers[$reference] = new TransferResponse(
            reference: $reference,
            status: $this->nextTransferStatus,
            amount: $amount,
            gatewayId: 'fake_trf_'.Str::lower(Str::random(16)),
        );
    }

    /**
     * Pending for an unknown transfer, for the same reason as collections and
     * with sharper consequences: a transfer reported failed reverts a seller's
     * payable, and doing that to money that really left pays them twice.
     */
    public function fetchTransfer(string $reference): TransferResponse
    {
        $this->recordCall(__FUNCTION__, ['reference' => $reference]);

        return $this->transfers[$reference] ?? new TransferResponse(
            reference: $reference,
            status: PaymentStatus::Pending,
            amount: Money::zero(),
        );
    }

    /**
     * A short stand-in for Lenco's bank list, enough for the payout form and
     * for a test to pick a code that resolves.
     *
     * @return array<int, BankOption>
     */
    public function listBanks(): array
    {
        return [
            new BankOption('01', 'Zanaco'),
            new BankOption('02', 'Stanbic Bank Zambia'),
            new BankOption('03', 'Absa Bank Zambia'),
            new BankOption('04', 'First National Bank Zambia'),
            new BankOption('05', 'Indo Zambia Bank'),
        ];
    }

    public function resolveBankAccount(string $accountNumber, string $bankCode): ?ResolvedAccount
    {
        $this->recordCall(__FUNCTION__, ['account_number' => $accountNumber, 'bank_code' => $bankCode]);

        if (! $this->resolutionSucceeds) {
            return null;
        }

        $bank = collect($this->listBanks())->firstWhere('code', $bankCode);

        if ($bank === null) {
            return null;
        }

        return new ResolvedAccount(
            accountName: $this->nameFor($accountNumber),
            accountNumber: $accountNumber,
            bankCode: $bank->code,
            bankName: $bank->name,
        );
    }

    public function resolveMobileMoney(string $phone, string $network): ?ResolvedAccount
    {
        $this->recordCall(__FUNCTION__, ['phone' => $phone, 'network' => $network]);

        if (! $this->resolutionSucceeds) {
            return null;
        }

        return new ResolvedAccount(
            accountName: $this->nameFor($phone),
            accountNumber: $phone,
            network: $network,
        );
    }

    public function createTransferRecipient(ResolvedAccount $account): string
    {
        $this->recordCall(__FUNCTION__, [
            'account_name' => $account->accountName,
            'account_number' => $account->accountNumber,
            'bank_code' => $account->bankCode,
            'network' => $account->network,
        ]);

        return 'fake_rcp_'.substr(md5($account->accountNumber.'|'.($account->bankCode ?? $account->network ?? '')), 0, 16);
    }

    /**
     * The same algorithm the real gateway uses (HMAC-SHA512), so a test that
     * passes signature verification here would pass it against Lenco too.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        return hash_equals(hash_hmac('sha512', $payload, $this->webhookSecret), $signature);
    }

    /**
     * Make every subsequent account lookup come back unrecognised, standing
     * in for a wrong account number or a closed account.
     */
    public function resolutionFails(): self
    {
        $this->resolutionSucceeds = false;

        return $this;
    }

    /**
     * Pin the name the gateway reports for an account or phone number.
     */
    public function stubAccountName(string $accountNumber, string $name): self
    {
        $this->accountNames[$accountNumber] = $name;

        return $this;
    }

    /**
     * The name behind an account: whatever a test pinned, else a stable
     * invented one so two lookups of the same number always agree.
     */
    private function nameFor(string $accountNumber): string
    {
        return $this->accountNames[$accountNumber]
            ?? 'ACCOUNT HOLDER '.strtoupper(substr(md5($accountNumber), 0, 6));
    }

    /**
     * The signature this fake would consider valid for a body — lets a test
     * post a webhook the way the real gateway would.
     */
    public function signatureFor(string $payload): string
    {
        return hash_hmac('sha512', $payload, $this->webhookSecret);
    }

    /**
     * Make the next initiated collection come back already successful.
     */
    public function collectionsSucceedImmediately(): self
    {
        $this->nextCollectionStatus = PaymentStatus::Successful;

        return $this;
    }

    /**
     * Settle an outstanding collection, standing in for the gateway webhook.
     */
    public function settleCollection(string $reference, ?Money $fee = null): CollectionResponse
    {
        $existing = $this->fetchCollection($reference);

        return $this->collections[$reference] = new CollectionResponse(
            reference: $existing->reference,
            status: PaymentStatus::Successful,
            amount: $existing->amount,
            gatewayId: $existing->gatewayId,
            checkoutUrl: $existing->checkoutUrl,
            fee: $fee,
        );
    }

    public function failCollection(string $reference, string $reason = 'Declined by fake gateway.'): CollectionResponse
    {
        $existing = $this->fetchCollection($reference);

        return $this->collections[$reference] = new CollectionResponse(
            reference: $existing->reference,
            status: PaymentStatus::Failed,
            amount: $existing->amount,
            gatewayId: $existing->gatewayId,
            failureReason: $reason,
        );
    }

    /**
     * Everything the fake has been told about, filtered to what reconciliation
     * would see: the gateway lists collections it actually took.
     *
     * @return array<int, CollectionResponse>
     */
    public function collectionsBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        return array_values(array_filter(
            $this->collections,
            static fn (CollectionResponse $collection): bool => $collection->status->isSettled(),
        ));
    }

    /**
     * @return array<int, SettlementRecord>
     */
    public function settlementsBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->settlements;
    }

    /**
     * @return array<int, GatewayTransaction>
     */
    public function transactionsBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->transactions;
    }

    /**
     * What the fake gateway says it is holding.
     *
     * Defaults to NULL — "the gateway could not say" — rather than to zero,
     * because that is the honest default for a gateway nobody has configured
     * and because it forces a test that cares about the balance check to say
     * what the balance is. A fake that silently answered K0.00 would make
     * every unconfigured test look like a catastrophic cash variance.
     */
    public function accountBalance(): ?Money
    {
        return $this->accountBalance;
    }

    /**
     * Tell the fake gateway what it is holding. Pass null for "cannot say".
     */
    public function stubAccountBalance(?Money $balance): self
    {
        $this->accountBalance = $balance;

        return $this;
    }

    /**
     * Put a settlement in front of the reconciler, standing in for money
     * Lenco has paid into MonaFind's bank account.
     */
    public function stubSettlement(SettlementRecord $settlement): self
    {
        $this->settlements[] = $settlement;

        return $this;
    }

    /**
     * Put a movement on the gateway's account statement.
     */
    public function stubTransaction(GatewayTransaction $transaction): self
    {
        $this->transactions[] = $transaction;

        return $this;
    }

    /**
     * Fail an outstanding transfer, standing in for a bounced payout.
     */
    public function failTransfer(string $reference, string $reason = 'Rejected by fake gateway.'): TransferResponse
    {
        $existing = $this->fetchTransfer($reference);

        return $this->transfers[$reference] = new TransferResponse(
            reference: $existing->reference,
            status: PaymentStatus::Failed,
            amount: $existing->amount,
            gatewayId: $existing->gatewayId,
            failureReason: $reason,
        );
    }

    /**
     * Make the next initiated transfer come back already successful, for
     * tests about what happens after a payout lands rather than about the
     * waiting.
     */
    public function transfersSucceedImmediately(): self
    {
        $this->nextTransferStatus = PaymentStatus::Successful;

        return $this;
    }

    public function settleTransfer(string $reference, ?Money $fee = null): TransferResponse
    {
        $existing = $this->fetchTransfer($reference);

        return $this->transfers[$reference] = new TransferResponse(
            reference: $existing->reference,
            status: PaymentStatus::Successful,
            amount: $existing->amount,
            gatewayId: $existing->gatewayId,
            fee: $fee,
        );
    }
}
