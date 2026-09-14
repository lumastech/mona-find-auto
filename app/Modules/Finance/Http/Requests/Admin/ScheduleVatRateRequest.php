<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Putting a VAT rate on the schedule.
 *
 * The rate is validated as a DECIMAL STRING rather than a number, because
 * that is what it is stored and calculated as — Money::percentage() parses it
 * exactly, and letting a float in here is how a tax figure acquires a binary
 * rounding error.
 *
 * `effective_from` may be in the past. That is deliberate and not an
 * oversight: the schedule has to be able to record what the rate WAS, for a
 * platform migrating off the old flat setting or correcting a date somebody
 * mistyped. It changes nothing about orders already settled, which carry
 * their rate in their own snapshot.
 */
class ScheduleVatRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        /* Changing a tax rate is a platform-administrator act, not a finance one. */
        return Gate::allows('admin-only');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'rate_percent' => ['required', 'string', 'regex:/^\d{1,2}(\.\d{1,2})?$/'],
            'effective_from' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rate_percent.regex' => __('Give the rate as a percentage with up to two decimals, such as "16" or "16.50".'),
        ];
    }
}
