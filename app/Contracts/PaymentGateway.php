<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Integrations\Payments\Data\BankOption;
use App\Integrations\Payments\Data\CollectionResponse;
use App\Integrations\Payments\Data\GatewayTransaction;
use App\Integrations\Payments\Data\ResolvedAccount;
use App\Integrations\Payments\Data\SettlementRecord;
use App\Integrations\Payments\Data\TransferResponse;
use App\Support\Money\Money;
use Carbon\CarbonInterface;

/**
 * Money in and money out. Implemented for real by the Lenco gateway and by a
 * fake for tests; nothing outside this boundary may talk to a payment API.
 */
interface PaymentGateway
{
    /**
     * Build the payment reference for an order group attempt.
     *
     * Format: MFA-{orderGroupId}-{attempt}, restricted to [A-Za-z0-9._-].
     */
    public function reference(string $orderGroupId, int $attempt): string;

    /**
     * Start a collection (buyer pays). Returns the attempt as the gateway
     * sees it, including the URL the checkout widget should open.
     *
     * @param  array{name?: string, email?: string, phone?: string}  $customer
     * @param  array<string, scalar|null>  $metadata
     */
    public function initiateCollection(
        Money $amount,
        string $reference,
        array $customer,
        array $metadata = [],
    ): CollectionResponse;

    /**
     * Push a mobile-money prompt to a phone and wait for the customer to
     * approve it on the handset.
     *
     * The widget's alternative, for the mobile app and anywhere else there is
     * no browser to open a checkout in. The response is all but always
     * Pending — the money arrives when the customer enters their PIN, minutes
     * later, via webhook.
     *
     * @param  array<string, scalar|null>  $metadata
     */
    public function collectMobileMoney(
        Money $amount,
        string $reference,
        string $phone,
        string $operator,
        array $metadata = [],
    ): CollectionResponse;

    /**
     * Re-read a collection from the gateway. Every payment is confirmed this
     * way server-side, regardless of what the browser or a webhook claimed.
     */
    public function fetchCollection(string $reference): CollectionResponse;

    /**
     * Start a transfer (platform pays a seller out).
     *
     * @param  array{account_number?: string, bank_code?: string, account_name?: string, phone?: string}  $recipient
     * @param  array<string, scalar|null>  $metadata
     */
    public function initiateTransfer(
        Money $amount,
        string $reference,
        array $recipient,
        array $metadata = [],
    ): TransferResponse;

    /**
     * Re-read a transfer from the gateway.
     */
    public function fetchTransfer(string $reference): TransferResponse;

    /**
     * The banks the gateway can pay out to, for the payout-account form.
     *
     * @return array<int, BankOption>
     */
    public function listBanks(): array;

    /**
     * Look up the name behind a bank account. Null when the gateway does not
     * recognise the account — which is what blocks the seller from saving it.
     */
    public function resolveBankAccount(string $accountNumber, string $bankCode): ?ResolvedAccount;

    /**
     * Look up the name behind a mobile-money number. Null when the gateway
     * does not recognise it on that network.
     */
    public function resolveMobileMoney(string $phone, string $network): ?ResolvedAccount;

    /**
     * Register a resolved account as a transfer recipient, returning the id
     * payouts will later be addressed to.
     */
    public function createTransferRecipient(ResolvedAccount $account): string;

    /**
     * Confirm that an inbound webhook body really came from the gateway.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool;

    /**
     * Every collection the gateway saw in a window.
     *
     * The three listings below exist for nightly reconciliation, which is the
     * only thing that asks the gateway what it thinks happened rather than
     * asking about one payment we already know about. Each pages internally
     * and returns the lot.
     *
     * @return array<int, CollectionResponse>
     */
    public function collectionsBetween(CarbonInterface $from, CarbonInterface $to): array;

    /**
     * Every settlement into MonaFind's account in a window.
     *
     * @return array<int, SettlementRecord>
     */
    public function settlementsBetween(CarbonInterface $from, CarbonInterface $to): array;

    /**
     * Every movement on MonaFind's gateway account in a window.
     *
     * @return array<int, GatewayTransaction>
     */
    public function transactionsBetween(CarbonInterface $from, CarbonInterface $to): array;

    /**
     * What the gateway says is sitting in MonaFind's account right now.
     *
     * The outside half of the platform's daily balance check: the ledger's
     * `platform_cash` is what the books say the platform holds at Lenco, and
     * this is what Lenco says. The two drifting apart is the single most
     * important thing a finance team can be told, because every other figure
     * on the platform is derived from books that have stopped describing
     * reality.
     *
     * Returns null when the gateway cannot say — no account configured, or a
     * request that failed. Null means "unknown" and must never be read as
     * zero: a balance check that treats an unreachable gateway as an empty
     * account reports a catastrophic variance every time the network blips.
     */
    public function accountBalance(): ?Money;
}
