<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

/**
 * Where a manual adjustment has got to.
 *
 * An adjustment is the only way a human writes directly into the ledger, so
 * it is the only thing on the platform that needs two people: finance drafts
 * it, an administrator approves it, and only then is anything posted. A
 * pending adjustment has moved no money at all.
 */
enum AdjustmentStatus: string
{
    case Pending = 'pending';

    case Approved = 'approved';

    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting approval',
            self::Approved => 'Approved and posted',
            self::Rejected => 'Rejected',
        };
    }

    public function isDecided(): bool
    {
        return $this !== self::Pending;
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
