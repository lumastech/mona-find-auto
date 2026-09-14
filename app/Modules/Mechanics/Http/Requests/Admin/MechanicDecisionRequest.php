<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Stringable;

/**
 * A reviewer moving a mechanic's application along.
 *
 * The note is staff-only and stays on the profile for the next reviewer. It
 * is not shown to the applicant — that is what a rejection reason is for.
 */
class MechanicDecisionRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function note(): ?string
    {
        $note = $this->string('note')->trim()->toString();

        return $note === '' ? null : $note;
    }
}
