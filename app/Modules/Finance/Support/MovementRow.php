<?php

declare(strict_types=1);

namespace App\Modules\Finance\Support;

use stdClass;

/**
 * One aggregated row of ledger movement, typed.
 *
 * The metrics queries are written with the query builder rather than Eloquent
 * — they aggregate across three tables and instantiating models for sums
 * would be pure waste — which means the rows come back as `stdClass` with
 * whatever columns the select happened to name. That is workable and entirely
 * unreadable: every use site ends up casting `(string) $row->code` and
 * nothing tells you which columns a row is supposed to carry.
 *
 * This names them once. The casting happens here, at the boundary where the
 * database's loose types become the application's, and everything downstream
 * reads typed properties.
 */
final readonly class MovementRow
{
    public function __construct(
        /** The ledger account code, e.g. "platform_cash". */
        public string $code,
        /** The PostingRecipe value the entry was posted under. */
        public string $recipe,
        /** "debit" or "credit". */
        public string $direction,
        public int $total,
        /** The id of the entry's reference — an order, on the rows that have one. */
        public ?int $referenceId,
        /** The local (Lusaka) date of the posting, when the query asked for it. */
        public ?string $localDate,
        /** The grouping key, when a dimension scope added one. */
        public ?string $dimensionKey,
        /** The order id, when a per-order pass asked for it. */
        public ?int $orderId,
    ) {}

    public static function fromDatabase(stdClass $row): self
    {
        /* isset() is false for both an absent column and a NULL one, which is the same answer here. */
        $optionalInt = static fn (string $key): ?int => isset($row->{$key}) ? (int) $row->{$key} : null;

        $optionalString = static fn (string $key): ?string => isset($row->{$key}) ? (string) $row->{$key} : null;

        return new self(
            code: (string) $row->code,
            recipe: (string) $row->recipe,
            direction: (string) $row->direction,
            total: (int) $row->total,
            referenceId: $optionalInt('reference_id'),
            localDate: $optionalString('local_date'),
            /*
             * Distinguishes "the scope grouped by a column that was null for
             * this row" from "no scope asked for a dimension". Both read as
             * null here; only bySellerAttribute() consumes it, and it treats
             * null as the unattributable bucket, which is the same answer
             * either way.
             */
            dimensionKey: $optionalString('dimension_key'),
            orderId: $optionalInt('order_id'),
        );
    }
}
