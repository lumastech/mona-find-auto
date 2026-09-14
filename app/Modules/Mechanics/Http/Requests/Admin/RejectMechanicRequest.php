<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Stringable;

/**
 * Turning a mechanic's application down, or suspending an approved one.
 *
 * The reason is shown to the applicant, so it has to say what to fix rather
 * than only that something is wrong.
 */
class RejectMechanicRequest extends MechanicDecisionRequest
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
            'reason.required' => 'Tell the mechanic why, and what would change your mind.',
            'reason.min' => 'Give a reason the mechanic can act on.',
        ];
    }

    public function reason(): string
    {
        return $this->string('reason')->trim()->toString();
    }
}
