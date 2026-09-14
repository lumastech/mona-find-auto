<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * The life of a payout run.
 *
 * `Completed` means the run finished, NOT that every seller was paid — a
 * batch with three failed lines out of forty is complete and has three
 * failures. `Failed` is reserved for a run that could not be carried out at
 * all. Conflating the two would hide individual failures behind a green tick.
 */
enum PayoutBatchStatus: string
{
    case Draft = 'draft';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::AwaitingApproval => 'Awaiting approval',
            self::Approved => 'Approved',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Whether Finance may still approve this batch. */
    public function isApprovable(): bool
    {
        return $this === self::Draft || $this === self::AwaitingApproval;
    }

    /** Whether the money has started moving. Nothing may be edited after this. */
    public function hasStarted(): bool
    {
        return in_array($this, [self::Processing, self::Completed, self::Failed], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }
}
