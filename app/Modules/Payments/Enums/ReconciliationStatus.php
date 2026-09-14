<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * How a night's reconciliation ended.
 *
 * `Clean` and `Failed` are both meaningful and neither is the absence of the
 * other: a clean run compared both sides and found them equal, a failed run
 * could not compare them at all.
 */
enum ReconciliationStatus: string
{
    case Running = 'running';
    case Clean = 'clean';
    case Exceptions = 'exceptions';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Clean => 'Clean',
            self::Exceptions => 'Exceptions found',
            self::Failed => 'Failed',
        };
    }

    public function needsAttention(): bool
    {
        return $this === self::Exceptions || $this === self::Failed;
    }
}
