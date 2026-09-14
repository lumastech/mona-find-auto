<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Hiding or restoring a review.
 *
 * The reason is required in both directions and it is not a formality: it
 * goes into the audit row, it is what a seller is told when their review
 * disappears, and a moderator who has to write one hides fewer reviews they
 * merely disagree with.
 */
class ModerateRatingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
