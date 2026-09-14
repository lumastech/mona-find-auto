<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Enums;

/**
 * The steps of the seller sign-up wizard, in order.
 *
 * A draft records the furthest step reached, so somebody who closes the tab
 * on step four comes back to step four rather than to the beginning. The
 * order lives here because both the controller (which step may I save?) and
 * the page (which step do I show?) have to agree on it.
 */
enum RegistrationStep: string
{
    case Type = 'type';
    case Business = 'business';
    case Policies = 'policies';
    case Payout = 'payout';
    case Documents = 'documents';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::Type => 'Business type',
            self::Business => 'Business details',
            self::Policies => 'Your policies',
            self::Payout => 'Payout details',
            self::Documents => 'Documents',
            self::Review => 'Review & submit',
        };
    }

    public function position(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    public function next(): ?self
    {
        return self::cases()[$this->position()] ?? null;
    }

    public function previous(): ?self
    {
        return self::cases()[$this->position() - 2] ?? null;
    }

    /**
     * Whether this step comes at or before another — the check behind
     * "you cannot skip ahead to documents before you have chosen a type".
     */
    public function isAtOrBefore(self $other): bool
    {
        return $this->position() <= $other->position();
    }

    public static function first(): self
    {
        return self::Type;
    }

    /**
     * @return array<int, array{value: string, label: string, position: int}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $step): array => [
            'value' => $step->value,
            'label' => $step->label(),
            'position' => $step->position(),
        ], self::cases());
    }
}
