<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Exceptions;

use App\Modules\Ledger\Models\LedgerAdjustment;
use RuntimeException;

/**
 * Something asked a manual adjustment to do what its state does not allow.
 *
 * Almost always the dual-control rule: the person approving must not be the
 * person who drafted, and an adjustment already decided cannot be decided
 * again.
 */
class AdjustmentNotAllowed extends RuntimeException
{
    public static function alreadyDecided(LedgerAdjustment $adjustment): self
    {
        return new self(sprintf(
            'Adjustment #%d was already %s.',
            $adjustment->getKey(),
            $adjustment->status->value,
        ));
    }

    public static function selfApproval(): self
    {
        return new self('An adjustment must be approved by somebody other than the person who created it.');
    }
}
