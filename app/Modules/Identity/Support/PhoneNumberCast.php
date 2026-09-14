<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Normalises a phone column to E.164 on the way in.
 *
 * Reads come back as a plain string so a phone number is still just a string
 * to everything downstream; use ZambianPhone::tryParse() where the network or
 * a formatted rendering is needed.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class PhoneNumberCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        /* An unparseable number is stored as typed; validation is what rejects it. */
        return ZambianPhone::tryParse($value)?->e164() ?? $value;
    }
}
