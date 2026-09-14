<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Enums\MobileNetwork;
use Stringable;

/**
 * A Zambian mobile number, normalised to E.164.
 *
 * Numbers reach us in every shape a person might type — 0977123456,
 * 260 977 123 456, +260-977-123456 — and are stored in exactly one:
 * +260977123456. Storing one shape is what lets a login look a number up,
 * and what lets the payments module hand it to a mobile-money operator later
 * without re-parsing it.
 *
 * @immutable
 */
final readonly class ZambianPhone implements Stringable
{
    /** Zambia's country calling code. */
    public const COUNTRY_CODE = '260';

    /** Digits after the country code: a two-digit operator prefix plus seven. */
    private const NATIONAL_LENGTH = 9;

    /**
     * @param  string  $nationalNumber  The nine significant digits, e.g. "977123456".
     */
    private function __construct(
        public string $nationalNumber,
        public MobileNetwork $network,
    ) {}

    /**
     * Parse any reasonable rendering of a Zambian mobile number, or null when
     * it is not one.
     */
    public static function tryParse(?string $input): ?self
    {
        if ($input === null) {
            return null;
        }

        $national = self::toNationalDigits($input);

        if ($national === null) {
            return null;
        }

        $network = MobileNetwork::forPrefix(substr($national, 0, 2));

        return $network === null ? null : new self($national, $network);
    }

    /**
     * Whether the input is a valid Zambian mobile number.
     */
    public static function isValid(?string $input): bool
    {
        return self::tryParse($input) !== null;
    }

    /**
     * The E.164 form the database stores, e.g. "+260977123456".
     */
    public function e164(): string
    {
        return '+'.self::COUNTRY_CODE.$this->nationalNumber;
    }

    /**
     * The form Zambians write and read, e.g. "0977 123 456".
     */
    public function national(): string
    {
        return sprintf(
            '0%s %s %s',
            substr($this->nationalNumber, 0, 3),
            substr($this->nationalNumber, 3, 3),
            substr($this->nationalNumber, 6, 3),
        );
    }

    /**
     * The number with everything but the last three digits hidden, for
     * "we sent a code to 0977 ••• 456" prompts.
     */
    public function masked(): string
    {
        return sprintf(
            '0%s ••• %s',
            substr($this->nationalNumber, 0, 3),
            substr($this->nationalNumber, 6, 3),
        );
    }

    public function __toString(): string
    {
        return $this->e164();
    }

    /**
     * Reduce any input to the nine national digits, or null when it cannot be
     * one: too short, too long, or carrying a foreign country code.
     */
    private static function toNationalDigits(string $input): ?string
    {
        $digits = preg_replace('/\D/', '', $input) ?? '';

        /* Drop the international prefix in whichever way it was written. */
        if (str_starts_with($digits, '00'.self::COUNTRY_CODE)) {
            $digits = substr($digits, 2 + strlen(self::COUNTRY_CODE));
        } elseif (str_starts_with($digits, self::COUNTRY_CODE)) {
            $digits = substr($digits, strlen(self::COUNTRY_CODE));
        }

        /* A locally written number carries a trunk zero the E.164 form drops. */
        if (strlen($digits) === self::NATIONAL_LENGTH + 1 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === self::NATIONAL_LENGTH ? $digits : null;
    }
}
