<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Models\User;
use App\Modules\Identity\Models\City;
use App\Modules\Identity\Models\Province;
use App\Modules\Identity\Rules\CityInProvince;
use App\Modules\Identity\Rules\ZambianMobileNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Stringable;

/**
 * The validation rules for the fields on a MonaFind account.
 *
 * Registration, the profile screen and the JSON API all describe the same
 * person, so they share one definition of what a valid person looks like.
 */
trait AccountFieldRules
{
    /**
     * Name, email and phone.
     *
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    protected function identityRules(?int $userId = null): array
    {
        return [
            'first_name' => $this->personNameRules(),
            'last_name' => $this->personNameRules(),
            'email' => $this->emailRules($userId),
            'phone' => $this->phoneRules($userId),
        ];
    }

    /**
     * The account's own postal address.
     *
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    protected function addressRules(bool $required = true): array
    {
        $presence = $required ? 'required' : 'nullable';

        return [
            'province_id' => [$presence, 'integer', Rule::exists(Province::class, 'id')],
            'city_id' => [$presence, 'integer', Rule::exists(City::class, 'id'), new CityInProvince],
            'street' => [$presence, 'string', 'max:255'],
            'plot_number' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<int, ValidationRule|Stringable|array<mixed>|string>
     */
    protected function personNameRules(): array
    {
        return ['required', 'string', 'max:100'];
    }

    /**
     * @return array<int, ValidationRule|Stringable|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }

    /**
     * Callers must normalise the input first (see normalisePhone()), because
     * uniqueness is checked against the column, and the column holds E.164 —
     * "0977123456" and "+260977123456" are one number and must collide.
     *
     * @return array<int, ValidationRule|Stringable|array<mixed>|string>
     */
    protected function phoneRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            new ZambianMobileNumber,
            $userId === null
                ? Rule::unique(User::class, 'phone')
                : Rule::unique(User::class, 'phone')->ignore($userId),
        ];
    }

    /**
     * Rewrite the phone fields of an input array into E.164 so validation and
     * storage agree on what was typed.
     *
     * Unparseable input is left alone for ZambianMobileNumber to reject.
     *
     * @param  array<string, mixed>  $input
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    protected function normalisePhone(array $input, array $fields = ['phone']): array
    {
        foreach ($fields as $field) {
            $value = $input[$field] ?? null;

            if (is_string($value) && ($parsed = ZambianPhone::tryParse($value)) !== null) {
                $input[$field] = $parsed->e164();
            }
        }

        return $input;
    }
}
