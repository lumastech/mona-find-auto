<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Http\Requests\Storefront;

use App\Modules\Ratings\Enums\ReportReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Objecting to a review.
 *
 * "Something else" is the one reason that has to be explained, because a
 * report with no reason and no words is a moderator's wasted afternoon.
 */
class ReportRatingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'details' => [
                Rule::requiredIf(fn (): bool => $this->input('reason') === ReportReason::Other->value),
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'details.required' => __('Tell us what the problem is.'),
        ];
    }

    public function reason(): ReportReason
    {
        return ReportReason::from((string) $this->validated('reason'));
    }

    public function details(): ?string
    {
        $details = $this->validated('details');

        return is_string($details) ? $details : null;
    }
}
