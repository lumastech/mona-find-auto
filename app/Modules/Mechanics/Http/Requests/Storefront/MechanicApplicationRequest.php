<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Http\Requests\Storefront;

use App\Modules\Identity\Rules\CityInProvince;
use App\Modules\Identity\Rules\ZambianMobileNumber;
use App\Modules\Identity\Support\ZambianPhone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Stringable;

/**
 * The mechanic sign-up form.
 *
 * One form rather than a wizard. A seller's sign-up is staged because each
 * step writes something different — a business, then policies, then payout
 * details, then documents — and a mechanic's is one profile: personal
 * details, where they are, what they do, and where they have worked. Splitting
 * it would add four round trips and no state worth keeping.
 *
 * Specialities are validated against the controlled list by `exists`, and
 * filtered again in the service against the *active* list. Both, because the
 * rule here gives the applicant a message and the filter there is what stops
 * a retired speciality being claimed by a form somebody kept open.
 *
 * References are optional and always have been — they are a courtesy to the
 * reviewer, not a requirement — but a reference row that exists must carry a
 * name and a number, or it is a phone call nobody can make.
 */
class MechanicApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:120'],
            'headline' => ['nullable', 'string', 'max:160'],
            'bio' => ['nullable', 'string', 'max:2000'],

            'qualification' => ['required', 'string', 'max:160'],
            'qualification_institution' => ['nullable', 'string', 'max:160'],
            'qualification_year' => ['nullable', 'integer', 'min:1950', 'max:'.(int) date('Y')],
            'years_experience' => ['required', 'integer', 'min:0', 'max:70'],

            'province_id' => ['required', 'integer', 'exists:provinces,id'],
            'city_id' => ['required', 'integer', 'exists:cities,id', new CityInProvince],
            'street' => ['nullable', 'string', 'max:160'],
            'plot_number' => ['nullable', 'string', 'max:40'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'phone' => ['required', 'string', new ZambianMobileNumber],
            'email' => ['nullable', 'email', 'max:190'],

            'is_mobile' => ['boolean'],
            'accepting_work' => ['boolean'],

            /* At least one, or nobody looking for this work can find them. */
            'speciality_ids' => ['required', 'array', 'min:1', 'max:12'],
            'speciality_ids.*' => ['integer', Rule::exists('mechanic_specialities', 'id')->where('is_active', true)],

            'work_history' => ['nullable', 'array', 'max:20'],
            'work_history.*.employer' => ['required', 'string', 'max:160'],
            'work_history.*.role' => ['required', 'string', 'max:160'],
            'work_history.*.description' => ['nullable', 'string', 'max:1000'],
            'work_history.*.started_on' => ['nullable', 'date', 'before_or_equal:today'],
            'work_history.*.ended_on' => ['nullable', 'date', 'after_or_equal:work_history.*.started_on'],
            'work_history.*.is_current' => ['boolean'],

            'references' => ['nullable', 'array', 'max:5'],
            'references.*.name' => ['required', 'string', 'max:120'],
            'references.*.relationship' => ['nullable', 'string', 'max:120'],
            'references.*.phone' => ['required', 'string', new ZambianMobileNumber],
            'references.*.email' => ['nullable', 'email', 'max:190'],
            'references.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'speciality_ids.required' => 'Choose at least one speciality so buyers can find you.',
            'speciality_ids.*.exists' => 'Choose a speciality from the list.',
            'qualification.required' => 'Tell us your qualification — it is the main thing MonaFind checks.',
            'work_history.*.employer.required' => 'Name the garage or shop you worked at.',
            'references.*.phone.required' => 'A reference we cannot ring is not a reference.',
        ];
    }

    /**
     * Normalise every phone in the payload to E.164 before the rules run, so
     * what is validated is what gets stored.
     */
    protected function prepareForValidation(): void
    {
        $input = $this->all();

        $input['phone'] = $this->normalise($input['phone'] ?? null);

        if (is_array($input['references'] ?? null)) {
            foreach ($input['references'] as $index => $reference) {
                if (is_array($reference)) {
                    $input['references'][$index]['phone'] = $this->normalise($reference['phone'] ?? null);
                }
            }
        }

        $this->merge($input);
    }

    /**
     * The profile's own columns.
     *
     * @return array<string, mixed>
     */
    public function profileAttributes(): array
    {
        return collect($this->validated())
            ->only([
                'display_name', 'headline', 'bio',
                'qualification', 'qualification_institution', 'qualification_year', 'years_experience',
                'province_id', 'city_id', 'street', 'plot_number', 'latitude', 'longitude',
                'phone', 'email', 'is_mobile', 'accepting_work',
            ])
            ->all();
    }

    /**
     * @return array<int, int>
     */
    public function specialityIds(): array
    {
        /** @var array<int, int> $ids */
        $ids = array_map('intval', $this->validated('speciality_ids', []));

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function workHistory(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->validated('work_history', []);

        return array_values($rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function references(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->validated('references', []);

        return array_values($rows);
    }

    private function normalise(mixed $value): mixed
    {
        return is_string($value)
            ? (ZambianPhone::tryParse($value)?->e164() ?? $value)
            : $value;
    }
}
