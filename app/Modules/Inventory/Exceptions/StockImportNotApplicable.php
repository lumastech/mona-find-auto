<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Modules\Inventory\Models\StockImportBatch;
use RuntimeException;

/**
 * A seller tried to apply a batch that has already been applied, discarded,
 * or failed.
 *
 * Nearly always a double-click or a stale tab. Refusing it is what keeps a
 * bulk upload idempotent: applying the same file twice would take a shelf
 * from ten to ten, but a batch that also adjusts prices would fire a second
 * round of movements and notifications for nothing.
 */
class StockImportNotApplicable extends RuntimeException
{
    public static function inStatus(StockImportBatch $batch): self
    {
        return new self(sprintf(
            'This upload is %s and cannot be applied again.',
            strtolower($batch->status->label()),
        ));
    }
}
