<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * Why a quantity changed.
 *
 * Every row in the stock ledger carries one of these, so a seller staring at
 * a shelf that says three when they can count two has somewhere to look. The
 * ledger is append-only for the same reason the financial one is: a history
 * that can be edited answers no questions at all.
 */
enum StockMovementReason: string
{
    /** A buyer paid. The only reason stock comes down automatically. */
    case OrderPaid = 'order_paid';

    /** A paid order was cancelled or refunded, so the goods go back. */
    case OrderCancelled = 'order_cancelled';

    /** The seller typed a new quantity into the portal. */
    case SellerAdjustment = 'seller_adjustment';

    /** The seller uploaded a stock file. */
    case BulkImport = 'bulk_import';

    /** Staff corrected the figure, with a reason on the audit row. */
    case StaffCorrection = 'staff_correction';

    /** The listing was created or its variants were rewritten. */
    case ListingEdit = 'listing_edit';

    public function label(): string
    {
        return match ($this) {
            self::OrderPaid => 'Sold',
            self::OrderCancelled => 'Order cancelled',
            self::SellerAdjustment => 'Adjusted by seller',
            self::BulkImport => 'Bulk upload',
            self::StaffCorrection => 'Corrected by MonaFind',
            self::ListingEdit => 'Listing edited',
        };
    }

    /**
     * Whether this movement is the platform acting on an order, rather than
     * somebody choosing a number.
     *
     * Order-driven movements are the ones that must never be applied twice,
     * which is why they are the ones that carry an order reference.
     */
    public function isOrderDriven(): bool
    {
        return in_array($this, [self::OrderPaid, self::OrderCancelled], true);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $reason): array => [
            'value' => $reason->value,
            'label' => $reason->label(),
        ], self::cases());
    }
}
