<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

/**
 * The four things a finance team takes off the platform.
 *
 * MonaFind pushes nothing to an external accounting system — there is no ERP
 * behind this and there is not going to be one. These exports ARE the
 * hand-off: the client's accountant gets a file, and the four below are what
 * they need to close a period without a login.
 *
 * The list is closed on purpose. Each entry is a query written for the
 * question it answers, and an export somebody can name from a URL is a
 * different and much worse thing.
 */
enum FinanceExport: string
{
    case Orders = 'orders';

    case Payments = 'payments';

    case Journal = 'journal';

    case Payouts = 'payouts';

    public function label(): string
    {
        return match ($this) {
            self::Orders => 'Orders',
            self::Payments => 'Payments',
            self::Journal => 'Ledger journal',
            self::Payouts => 'Payouts',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Orders => 'One row per order that took payment: buyer, seller, totals and the terms it settled under.',
            self::Payments => 'Every observed state of every payment attempt, as the gateway reported it.',
            self::Journal => 'One row per journal LINE — the double-entry detail, which is what an accountant will want.',
            self::Payouts => 'Every payout line, its batch, who approved it and what the transfer did.',
        };
    }

    /**
     * The sheet name inside an XLSX workbook.
     */
    public function sheetName(): string
    {
        return $this->label();
    }

    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'description' => $case->description(),
        ], self::cases());
    }
}
