<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Requests\Admin;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;
use Stringable;

/**
 * Booking a visit to a seller's premises.
 */
class ScheduleInspectionRequest extends VerificationDecisionRequest
{
    /**
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'inspection_scheduled_for' => ['required', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'inspection_scheduled_for.after' => 'Pick a date in the future — the seller has to be told before you arrive.',
        ];
    }

    /**
     * Unlike the parent's, this date is always present: the rules above make
     * it required, so the caller does not have to cope with a null.
     */
    public function inspectionDate(): CarbonInterface
    {
        return Carbon::parse($this->string('inspection_scheduled_for')->toString());
    }
}
