<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Http\Requests\Seller;

use Illuminate\Contracts\Validation\ValidationRule;
use Stringable;

/**
 * A shop taking an endorsement back.
 *
 * A reason is required where a decline's note is not. Withdrawing removes a
 * badge a buyer may already have chosen a mechanic on, and it is kept
 * permanently on the row — so somebody has to say why.
 */
class RevokeEndorsementRequest extends EndorsementDecisionRequest
{
    /**
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:600'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why you are withdrawing this endorsement.',
            'reason.min' => 'Give a reason the mechanic can understand.',
        ];
    }

    public function reason(): string
    {
        return $this->string('reason')->trim()->toString();
    }
}
