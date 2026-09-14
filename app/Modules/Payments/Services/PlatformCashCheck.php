<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Contracts\PaymentGateway;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Services\LedgerBalances;
use App\Support\Money\Money;
use Throwable;

/**
 * Do the books and the bank agree about how much money there is?
 *
 * The daily cash check, and the narrowest question the reconciliation centre
 * asks. Nightly reconciliation compares a DAY's transactions line by line;
 * this compares one number with one number, right now: what `platform_cash`
 * says MonaFind holds at Lenco, against what Lenco says.
 *
 * It is worth having both. The line-by-line run can pass while the totals are
 * wrong — an entry posted against the wrong account balances perfectly and
 * reconciles perfectly — and a running total that has quietly drifted is the
 * one failure that invalidates every other figure on the platform.
 *
 * ## Unknown is not zero
 *
 * If the gateway cannot say, this reports that it cannot say. A check that
 * read an unreachable gateway as an empty account would raise a catastrophic
 * variance every time the network blipped, and the third false alarm is the
 * one after which nobody reads the alert again.
 *
 * ## It never posts anything
 *
 * Read-only, like everything else in the reconciliation centre. A drift is
 * something a human has to explain; an automatic correcting entry would make
 * the evidence disappear without the money coming back.
 *
 * ## Why it lives in Payments rather than Finance
 *
 * It reads the gateway, and the gateway belongs to this module. Finance
 * renders it on the dashboard, which is the right direction of dependency —
 * Finance is downstream of everything and may read any module's services;
 * nothing may read Finance's. Putting it the other way round would have the
 * reconciliation centre reaching into a reporting module for the answer to a
 * question about its own gateway.
 */
class PlatformCashCheck
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly LedgerBalances $balances,
    ) {}

    /**
     * @return array{
     *     ledgerNgwee: int,
     *     gatewayNgwee: int|null,
     *     varianceNgwee: int|null,
     *     status: string,
     *     message: string,
     *     checkedAt: string
     * }
     */
    public function run(): array
    {
        $ledger = $this->balances->forAccount(LedgerAccountCode::PlatformCash);
        $gateway = $this->askGateway();

        if ($gateway === null) {
            return $this->result($ledger, null, 'unknown', __(
                'Lenco did not report a balance, so the books could not be checked against it. '
                .'This is not a variance — it is an unanswered question.',
            ));
        }

        $variance = $ledger->minus($gateway);

        if ($variance->isZero()) {
            return $this->result($ledger, $gateway, 'balanced', __(
                'The ledger and Lenco agree to the ngwee.',
            ));
        }

        return $this->result($ledger, $gateway, 'variance', __(
            $variance->isPositive()
                ? 'The books show more cash than Lenco is holding. Money the platform believes it has may not have arrived.'
                : 'Lenco is holding more than the books show. Something was collected that the ledger has not recorded.',
        ));
    }

    /**
     * Ask the gateway, and treat a thrown request as an unanswered question
     * rather than as an outage worth failing a dashboard over.
     */
    private function askGateway(): ?Money
    {
        try {
            return $this->gateway->accountBalance();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     ledgerNgwee: int,
     *     gatewayNgwee: int|null,
     *     varianceNgwee: int|null,
     *     status: string,
     *     message: string,
     *     checkedAt: string
     * }
     */
    private function result(Money $ledger, ?Money $gateway, string $status, string $message): array
    {
        return [
            'ledgerNgwee' => $ledger->ngwee,
            'gatewayNgwee' => $gateway?->ngwee,
            'varianceNgwee' => $gateway === null ? null : $ledger->minus($gateway)->ngwee,
            'status' => $status,
            'message' => $message,
            'checkedAt' => now()->toIso8601String(),
        ];
    }
}
