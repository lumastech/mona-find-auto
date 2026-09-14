<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

/**
 * How loudly a banner reads.
 *
 * Three levels rather than a colour picker, because the point of the banner
 * is that a buyer on a slow phone can tell at a glance whether it concerns
 * them, and a platform whose every notice is red has no way to say that this
 * one is.
 */
enum AnnouncementLevel: string
{
    /** Something new. Dismissible, quiet. */
    case Info = 'info';

    /** Something degraded: payments slow, a region undelivered. */
    case Warning = 'warning';

    /** Something broken that costs money if ignored. */
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Information',
            self::Warning => 'Warning',
            self::Critical => 'Critical',
        };
    }

    /**
     * Whether a visitor may dismiss it for themselves.
     *
     * A critical banner is not dismissible: it is up because something is
     * actually wrong, and it comes down when staff take it down.
     */
    public function isDismissible(): bool
    {
        return $this !== self::Critical;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $level): array => [
            'value' => $level->value,
            'label' => $level->label(),
        ], self::cases());
    }
}
