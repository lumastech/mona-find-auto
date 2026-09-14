<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

/**
 * Whether a CMS page is live.
 *
 * There is no "scheduled": a page goes up when somebody presses publish. The
 * scheduling the brief asks for belongs to announcements, which have a window
 * by nature; giving the terms of use a future start date would mean the
 * version a buyer accepted at checkout could depend on the clock.
 */
enum ContentPageStatus: string
{
    /** Written, not published. The storefront 404s it. */
    case Draft = 'draft';

    /** Live at its slug. */
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Draft => 'secondary',
            self::Published => 'default',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $status): array => [
            'value' => $status->value,
            'label' => $status->label(),
        ], self::cases());
    }
}
