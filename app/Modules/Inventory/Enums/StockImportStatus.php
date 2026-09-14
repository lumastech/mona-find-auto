<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * Where a bulk stock upload has got to.
 *
 * The two-step shape is the point. A seller uploads, sees exactly which rows
 * are wrong and why, and only then applies the good ones — rather than
 * discovering afterwards that a typo in row 40 quietly changed a price.
 */
enum StockImportStatus: string
{
    /** Parsed and checked. The seller has a report and has not applied it yet. */
    case AwaitingConfirmation = 'awaiting_confirmation';

    /** Queued, or being written to the shelves right now. */
    case Applying = 'applying';

    /** Every good row was applied. Bad rows are listed in the report. */
    case Applied = 'applied';

    /** The file could not be read at all, so nothing was applied. */
    case Failed = 'failed';

    /** The seller looked at the report and walked away. */
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingConfirmation => 'Ready to apply',
            self::Applying => 'Applying',
            self::Applied => 'Applied',
            self::Failed => 'Failed',
            self::Discarded => 'Discarded',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Applied => 'default',
            self::AwaitingConfirmation, self::Applying => 'secondary',
            self::Failed => 'destructive',
            self::Discarded => 'outline',
        };
    }

    /**
     * Whether the seller can still choose to apply this batch.
     */
    public function isApplicable(): bool
    {
        return $this === self::AwaitingConfirmation;
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Applied, self::Failed, self::Discarded], true);
    }
}
