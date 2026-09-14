<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Stringable;

/**
 * Turning a seller's application down, or suspending a verified one.
 *
 * The reason is shown to the seller, so it has to say what to fix rather than
 * only that something is wrong.
 */
class RejectSellerRequest extends VerificationDecisionRequest
{
    /**
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'reason' => ['required', 'string', 'min:15', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Tell the seller why, and what would change your mind.',
            'reason.min' => 'Give a reason the seller can act on.',
        ];
    }

    public function reason(): string
    {
        return $this->string('reason')->trim()->toString();
    }
}
