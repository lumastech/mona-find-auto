<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Http\Requests\Seller;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Stringable;

/**
 * A shop answering an endorsement request.
 *
 * The note is optional and goes to the mechanic. Endorsing needs no
 * explanation; declining is kinder with one, and neither is worth blocking on.
 */
class EndorsementDecisionRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:600'],
        ];
    }

    public function note(): ?string
    {
        $note = $this->string('note')->trim()->toString();

        return $note === '' ? null : $note;
    }
}
