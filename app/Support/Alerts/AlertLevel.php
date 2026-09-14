<?php

declare(strict_types=1);

namespace App\Support\Alerts;

/**
 * How loud an operational alert is.
 *
 * Not decoration: the level decides the subject-line prefix an operator's
 * inbox rule filters on, and — more importantly — how long the same alert is
 * suppressed for. An alert that arrives five hundred times in a minute
 * teaches people to filter alerts out.
 */
enum AlertLevel: string
{
    /** Worth knowing at some point. A single failed job. */
    case Notice = 'notice';

    /** Somebody should look today. A reconciliation variance. */
    case Warning = 'warning';

    /** Money is stuck or moving wrongly. Somebody should look now. */
    case Critical = 'critical';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * How long an identical alert is suppressed after one goes out.
     *
     * A critical alert repeats sooner because the thing it describes is
     * costing money while nobody is looking.
     */
    public function cooldownMinutes(): int
    {
        return match ($this) {
            self::Notice => 60,
            self::Warning => 30,
            self::Critical => 10,
        };
    }

    public function subjectPrefix(): string
    {
        return match ($this) {
            self::Notice => '[MonaFind]',
            self::Warning => '[MonaFind WARNING]',
            self::Critical => '[MonaFind CRITICAL]',
        };
    }
}
