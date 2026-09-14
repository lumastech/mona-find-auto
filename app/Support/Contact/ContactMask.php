<?php

declare(strict_types=1);

namespace App\Support\Contact;

use App\Modules\Identity\Support\ZambianPhone;
use Illuminate\Support\Str;

/**
 * How a contact detail looks to somebody who is not allowed to read it.
 *
 * The brief's rule is the same wherever contact details appear: a guest sees
 * the field labels and a masked shape, with a prompt to log in. Two modules
 * now show contact blocks — sellers and mechanics — and the masking lives
 * here so there is one rule rather than two that quietly diverge.
 *
 * Masking is a server-side transformation, never a CSS blur. A blurred real
 * number is still in the response and anybody can read it out of the page
 * source; what a guest is sent must not contain the value at all.
 */
final class ContactMask
{
    /**
     * Keep the country code so a buyer can see it is a Zambian number, and
     * throw the rest away.
     */
    public static function phone(string $phone): string
    {
        return ZambianPhone::tryParse($phone) === null ? '••• ••• •••' : '+260 ••• ••• •••';
    }

    /**
     * Keep the shape of an address — a name, an at, a domain — and none of it.
     */
    public static function email(string $email): string
    {
        $domain = Str::after($email, '@');

        return '••••••@••••••'.(str_contains($domain, '.') ? '.'.Str::afterLast($domain, '.') : '');
    }

    /**
     * Keep the word count so the block does not jump about when it unblurs.
     */
    public static function name(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];

        return trim(implode(' ', array_map(
            static fn (string $word): string => str_repeat('•', max(3, min(mb_strlen($word), 8))),
            array_slice(array_filter($words), 0, 3),
        ))) ?: '••••••';
    }

    /**
     * One field of a contact block, masked unless the viewer may read it.
     *
     * @param  callable(string): string  $mask
     * @return array{key: string, label: string, value: string}
     */
    public static function field(string $key, string $label, ?string $value, bool $visible, callable $mask): array
    {
        $value = (string) $value;

        return [
            'key' => $key,
            'label' => $label,
            'value' => $visible ? $value : $mask($value),
        ];
    }
}
