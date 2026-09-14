<?php

declare(strict_types=1);

namespace App\Modules\Identity\Rules;

use App\Modules\Identity\Models\City;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Checks that the chosen city actually sits in the chosen province.
 *
 * Without this a buyer could save "Livingstone, Copperbelt", and every
 * distance calculation and delivery quote built on that address afterwards
 * would be quietly wrong.
 */
class CityInProvince implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $provinceField = 'province_id') {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $provinceId = $this->data[$this->provinceField] ?? null;

        /* Nothing to check against yet; the province's own rules report that. */
        if ($provinceId === null || $value === null) {
            return;
        }

        $belongs = City::query()
            ->whereKey($value)
            ->where('province_id', $provinceId)
            ->exists();

        if (! $belongs) {
            $fail('The selected city is not in the selected province.');
        }
    }
}
