<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The one reply a seller or mechanic gets.
 *
 * A minimum length, because "ok" under a one-star review reads worse than no
 * answer at all, and the seller only gets to post this once.
 */
class ReplyToRatingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reply' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reply.min' => __('Write a sentence — buyers read replies as carefully as reviews.'),
        ];
    }

    public function reply(): string
    {
        return (string) $this->validated('reply');
    }
}
