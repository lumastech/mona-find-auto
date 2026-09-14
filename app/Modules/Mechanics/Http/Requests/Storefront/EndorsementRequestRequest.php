<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Http\Requests\Storefront;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Stringable;

/**
 * A mechanic asking a shop to vouch for them.
 *
 * The message is optional but capped: it is read by a shop deciding whether
 * to put its name on somebody's profile, and a wall of text is not what helps
 * them decide.
 */
class EndorsementRequestRequest extends FormRequest
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
            'seller_id' => ['required', 'integer', 'exists:sellers,id'],
            'message' => ['nullable', 'string', 'max:600'],
        ];
    }

    public function sellerId(): int
    {
        return (int) $this->validated('seller_id');
    }

    public function message(): ?string
    {
        $message = $this->string('message')->trim()->toString();

        return $message === '' ? null : $message;
    }
}
