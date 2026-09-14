<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * Zambia's three mobile networks, identified by the subscriber prefix.
 *
 * The network matters beyond validation: mobile-money collections and payouts
 * are routed per operator, so a number is resolved to its network once, at
 * registration, rather than re-parsed at payment time.
 */
enum MobileNetwork: string
{
    case Mtn = 'mtn';
    case Airtel = 'airtel';
    case Zamtel = 'zamtel';

    /**
     * The national prefixes each network issues, without the leading zero.
     *
     * @return array<int, string>
     */
    public function prefixes(): array
    {
        return match ($this) {
            self::Mtn => ['96', '76'],
            self::Airtel => ['97', '77'],
            self::Zamtel => ['95', '75'],
        };
    }

    /**
     * The network that issued a two-digit subscriber prefix, or null when no
     * Zambian operator uses it.
     */
    public static function forPrefix(string $prefix): ?self
    {
        foreach (self::cases() as $network) {
            if (in_array($prefix, $network->prefixes(), true)) {
                return $network;
            }
        }

        return null;
    }

    /**
     * Every prefix issued in Zambia, for validation messages and tests.
     *
     * @return array<int, string>
     */
    public static function allPrefixes(): array
    {
        return array_merge(...array_map(
            static fn (self $network): array => $network->prefixes(),
            self::cases(),
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::Mtn => 'MTN',
            self::Airtel => 'Airtel',
            self::Zamtel => 'Zamtel',
        };
    }
}
