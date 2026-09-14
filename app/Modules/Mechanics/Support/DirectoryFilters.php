<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Support;

use Illuminate\Http\Request;

/**
 * What a visitor asked the directory for.
 *
 * A typed object rather than an array of query-string values, so the web page
 * and the API build the same filters from the same input and neither can
 * invent one the other does not honour. Everything is nullable: an empty
 * object is the whole directory.
 *
 * Note what is absent — there is no status filter. A caller does not get to
 * ask for unapproved profiles, however they spell it.
 */
final readonly class DirectoryFilters
{
    public function __construct(
        public ?string $search = null,
        public ?int $specialityId = null,
        public ?int $provinceId = null,
        public ?int $cityId = null,
        public ?float $minimumRating = null,
        public bool $acceptingWork = false,
        public bool $endorsedOnly = false,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            search: self::stringOrNull($validated['search'] ?? null),
            specialityId: self::intOrNull($validated['speciality_id'] ?? null),
            provinceId: self::intOrNull($validated['province_id'] ?? null),
            cityId: self::intOrNull($validated['city_id'] ?? null),
            minimumRating: isset($validated['min_rating']) ? (float) $validated['min_rating'] : null,
            acceptingWork: (bool) ($validated['accepting_work'] ?? false),
            endorsedOnly: (bool) ($validated['endorsed_only'] ?? false),
        );
    }

    /**
     * The validation rules both surfaces apply before building one.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'speciality_id' => ['nullable', 'integer', 'exists:mechanic_specialities,id'],
            'province_id' => ['nullable', 'integer', 'exists:provinces,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'min_rating' => ['nullable', 'numeric', 'min:1', 'max:5'],
            'accepting_work' => ['nullable', 'boolean'],
            'endorsed_only' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The filters as the page needs them back, to redraw its own controls.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'speciality_id' => $this->specialityId,
            'province_id' => $this->provinceId,
            'city_id' => $this->cityId,
            'min_rating' => $this->minimumRating,
            'accepting_work' => $this->acceptingWork,
            'endorsed_only' => $this->endorsedOnly,
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return self::fromValidated($request->validate(self::rules()));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
