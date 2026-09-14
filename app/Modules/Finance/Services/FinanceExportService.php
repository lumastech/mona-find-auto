<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Enums\FinanceExport;
use App\Modules\Finance\Support\MetricWindow;
use App\Support\Money\Money;
use App\Support\Spreadsheet\SpreadsheetWriter;
use Illuminate\Support\Facades\DB;

/**
 * The hand-off to the client's accountant.
 *
 * There is no ERP behind MonaFind and no accounting integration to push to —
 * the brief removed Odoo and did not replace it. These files are therefore
 * not a convenience, they are the interface: somebody outside the platform
 * closes a period from them, so they have to be complete, plainly labelled
 * and stable between releases.
 *
 * ## Decisions a reader will want explained
 *
 * Amounts are written in KWACHA with two decimals, not in ngwee. Internally
 * every figure is an integer minor unit and that is right; in a file opened
 * by a person in a spreadsheet, a column reading 155000 where they expect
 * K1,550.00 is an error waiting to be copied into a return.
 *
 * The journal export is one row per LINE, not per entry. An entry is balanced
 * by construction and its total tells an accountant nothing about which
 * account moved; the lines are the double entry, and the entry's own columns
 * are repeated onto each so the file can be sorted and filtered flat.
 *
 * Everything is bounded by a window and ordered oldest-first, so the same
 * request run twice produces the same file.
 */
class FinanceExportService
{
    /**
     * Build one export as a CSV or an XLSX.
     */
    public function build(FinanceExport $export, MetricWindow $window, string $format): string
    {
        [$headers, $rows] = $this->contents($export, $window);

        $writer = new SpreadsheetWriter($headers, $rows, $export->sheetName());

        return $format === 'xlsx' ? $writer->toXlsx() : $writer->toCsv();
    }

    public function filename(FinanceExport $export, MetricWindow $window, string $format): string
    {
        return sprintf(
            'monafind-%s-%s-to-%s.%s',
            $export->value,
            $window->from->toDateString(),
            $window->to->toDateString(),
            $format,
        );
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int|null>>}
     */
    private function contents(FinanceExport $export, MetricWindow $window): array
    {
        return match ($export) {
            FinanceExport::Orders => $this->orders($window),
            FinanceExport::Payments => $this->payments($window),
            FinanceExport::Journal => $this->journal($window),
            FinanceExport::Payouts => $this->payouts($window),
        };
    }

    /**
     * Orders that took payment in the window.
     *
     * Bounded by `paid_at` rather than `created_at`: an accountant is closing
     * a period by when the money moved, and an order raised in August and
     * paid in September belongs to September.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int|null>>}
     */
    private function orders(MetricWindow $window): array
    {
        [$from, $to] = $window->bounds();

        $rows = DB::table('orders')
            ->join('sellers', 'sellers.id', '=', 'orders.seller_id')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->whereBetween('orders.paid_at', [$from, $to])
            ->orderBy('orders.paid_at')
            ->orderBy('orders.id')
            ->select(
                'orders.number',
                'orders.status',
                'orders.payment_mode',
                'orders.fulfilment_method',
                'sellers.business_name',
                'sellers.type as seller_type',
                'users.name as buyer_name',
                'orders.items_total_ngwee',
                'orders.delivery_fee_ngwee',
                'orders.total_ngwee',
                'orders.refunded_amount_ngwee',
                'orders.monetisation_snapshot',
                'orders.paid_at',
                'orders.completed_at',
            )
            ->get();

        return [
            [
                'Order', 'Status', 'Payment mode', 'Fulfilment', 'Seller', 'Seller type', 'Buyer',
                'Goods (ZMW)', 'Delivery (ZMW)', 'Total (ZMW)', 'Refunded (ZMW)',
                'Commission rate', 'VAT on commission rate', 'Paid at', 'Completed at',
            ],
            $rows->map(function (object $row): array {
                $snapshot = json_decode((string) ($row->monetisation_snapshot ?? '{}'), true) ?: [];

                return [
                    (string) $row->number,
                    (string) $row->status,
                    (string) ($row->payment_mode ?? ''),
                    (string) $row->fulfilment_method,
                    (string) $row->business_name,
                    (string) $row->seller_type,
                    (string) $row->buyer_name,
                    $this->kwacha((int) $row->items_total_ngwee),
                    $this->kwacha((int) $row->delivery_fee_ngwee),
                    $this->kwacha((int) $row->total_ngwee),
                    $this->kwacha((int) $row->refunded_amount_ngwee),
                    $this->rateLabel($snapshot),
                    (string) ($snapshot['vat_on_commission_percent'] ?? '').'%',
                    (string) ($row->paid_at ?? ''),
                    (string) ($row->completed_at ?? ''),
                ];
            })->all(),
        ];
    }

    /**
     * Every observed state of every payment attempt.
     *
     * `payments` is an append-only log rather than one mutable row per
     * payment, so a single attempt legitimately appears more than once —
     * pending, then successful. The file says so by carrying the observation
     * time on every row; collapsing it to a latest-state view here would hide
     * exactly the history an accountant querying a discrepancy needs.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int|null>>}
     */
    private function payments(MetricWindow $window): array
    {
        [$from, $to] = $window->bounds();

        $rows = DB::table('payments')
            ->leftJoin('users', 'users.id', '=', 'payments.user_id')
            ->whereBetween('payments.observed_at', [$from, $to])
            ->orderBy('payments.observed_at')
            ->orderBy('payments.id')
            ->select(
                'payments.reference',
                'payments.attempt',
                'payments.status',
                'payments.channel',
                'payments.source',
                'payments.amount_ngwee',
                'payments.fee_ngwee',
                'payments.settled_ngwee',
                'payments.lenco_id',
                'payments.failure_reason',
                'users.name as buyer_name',
                'payments.observed_at',
            )
            ->get();

        return [
            [
                'Reference', 'Attempt', 'Status', 'Channel', 'Observed via', 'Amount (ZMW)',
                'Gateway fee (ZMW)', 'Settled (ZMW)', 'Lenco id', 'Failure reason', 'Buyer', 'Observed at',
            ],
            $rows->map(fn (object $row): array => [
                (string) $row->reference,
                (int) $row->attempt,
                (string) $row->status,
                (string) ($row->channel ?? ''),
                (string) $row->source,
                $this->kwacha((int) $row->amount_ngwee),
                $row->fee_ngwee === null ? '' : $this->kwacha((int) $row->fee_ngwee),
                $row->settled_ngwee === null ? '' : $this->kwacha((int) $row->settled_ngwee),
                (string) ($row->lenco_id ?? ''),
                (string) ($row->failure_reason ?? ''),
                (string) ($row->buyer_name ?? ''),
                (string) $row->observed_at,
            ])->all(),
        ];
    }

    /**
     * The double entry itself: one row per journal LINE.
     *
     * Debit and credit are separate columns rather than one signed amount,
     * because that is how a journal is read and how it is checked — the two
     * columns must foot to the same total, and a file in which they do is one
     * an accountant can trust at a glance.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int|null>>}
     */
    private function journal(MetricWindow $window): array
    {
        [$from, $to] = $window->bounds();

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('ledger_accounts as la', 'la.id', '=', 'jl.ledger_account_id')
            ->whereBetween('jl.posted_at', [$from, $to])
            ->orderBy('jl.posted_at')
            ->orderBy('jl.id')
            ->select(
                'jl.posted_at',
                'je.uuid as entry_uuid',
                'je.recipe',
                'je.description',
                'je.idempotency_key',
                'je.actor_label',
                'la.code as account',
                'la.name as account_name',
                'jl.direction',
                'jl.amount_ngwee',
                'jl.subject_type',
                'jl.subject_id',
                'jl.memo',
            )
            ->get();

        return [
            [
                'Posted at', 'Entry', 'Recipe', 'Description', 'Account', 'Account name',
                'Debit (ZMW)', 'Credit (ZMW)', 'Subject', 'Memo', 'Actor', 'Idempotency key',
            ],
            $rows->map(function (object $row): array {
                $isDebit = (string) $row->direction === 'debit';
                $amount = $this->kwacha((int) $row->amount_ngwee);

                return [
                    (string) $row->posted_at,
                    (string) $row->entry_uuid,
                    (string) $row->recipe,
                    (string) $row->description,
                    (string) $row->account,
                    (string) $row->account_name,
                    $isDebit ? $amount : '',
                    $isDebit ? '' : $amount,
                    $this->subjectLabel(
                        $row->subject_type === null ? null : (string) $row->subject_type,
                        $row->subject_id === null ? null : (int) $row->subject_id,
                    ),
                    (string) ($row->memo ?? ''),
                    (string) $row->actor_label,
                    (string) $row->idempotency_key,
                ];
            })->all(),
        ];
    }

    /**
     * Payout lines, with their batch and its dual control on every row.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int|null>>}
     */
    private function payouts(MetricWindow $window): array
    {
        [$from, $to] = $window->bounds();

        $rows = DB::table('payout_lines as pl')
            ->join('payout_batches as pb', 'pb.id', '=', 'pl.payout_batch_id')
            ->join('sellers', 'sellers.id', '=', 'pl.seller_id')
            ->leftJoin('users as prepared', 'prepared.id', '=', 'pb.prepared_by')
            ->leftJoin('users as approved', 'approved.id', '=', 'pb.approved_by')
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('pl.created_at', [$from, $to])
                    ->orWhereBetween('pl.settled_at', [$from, $to]);
            })
            ->orderBy('pl.created_at')
            ->orderBy('pl.id')
            ->select(
                'pb.reference as batch',
                'pb.status as batch_status',
                'pl.reference',
                'pl.status',
                'sellers.business_name',
                'pl.amount_ngwee',
                'pl.fee_ngwee',
                'pl.method',
                'pl.resolved_name_at_payout',
                'pl.block_reason',
                'pl.failure_reason',
                'pl.lenco_transfer_id',
                'prepared.name as prepared_by',
                'approved.name as approved_by',
                'pl.sent_at',
                'pl.settled_at',
            )
            ->get();

        return [
            [
                'Batch', 'Batch status', 'Line reference', 'Line status', 'Seller', 'Amount (ZMW)',
                'Gateway fee (ZMW)', 'Method', 'Destination name', 'Block reason', 'Failure reason',
                'Lenco transfer id', 'Prepared by', 'Approved by', 'Sent at', 'Settled at',
            ],
            $rows->map(fn (object $row): array => [
                (string) $row->batch,
                (string) $row->batch_status,
                (string) $row->reference,
                (string) $row->status,
                (string) $row->business_name,
                $this->kwacha((int) $row->amount_ngwee),
                $row->fee_ngwee === null ? '' : $this->kwacha((int) $row->fee_ngwee),
                (string) ($row->method ?? ''),
                (string) ($row->resolved_name_at_payout ?? ''),
                (string) ($row->block_reason ?? ''),
                (string) ($row->failure_reason ?? ''),
                (string) ($row->lenco_transfer_id ?? ''),
                (string) ($row->prepared_by ?? ''),
                (string) ($row->approved_by ?? ''),
                (string) ($row->sent_at ?? ''),
                (string) ($row->settled_at ?? ''),
            ])->all(),
        ];
    }

    /**
     * Ngwee as a plain kwacha decimal — no symbol, no thousands separator, so
     * a spreadsheet reads the cell as a number.
     */
    private function kwacha(int $ngwee): string
    {
        return Money::ofNgwee($ngwee)->toDecimalString();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function rateLabel(array $snapshot): string
    {
        return ($snapshot['commission_type'] ?? 'percentage') === 'flat'
            ? $this->kwacha((int) ($snapshot['commission_flat_ngwee'] ?? 0)).' flat'
            : (string) ($snapshot['commission_percent'] ?? '0.00').'%';
    }

    /**
     * Who a line was posted against, in words.
     *
     * A platform-wide account carries no subject at all, and saying so beats
     * an empty cell an accountant has to ask about.
     */
    private function subjectLabel(?string $subjectType, ?int $subjectId): string
    {
        if ($subjectType === null || $subjectId === null) {
            return 'Platform';
        }

        return class_basename($subjectType).' #'.$subjectId;
    }
}
