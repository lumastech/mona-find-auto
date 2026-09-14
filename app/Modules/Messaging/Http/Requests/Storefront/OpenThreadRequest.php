<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Requests\Storefront;

use App\Modules\Messaging\Enums\ThreadSubjectType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Open a conversation about this."
 *
 * The subject arrives as a TYPE NAME plus an id rather than as a morph class,
 * because a request that carries a class name is a request that can be made
 * to instantiate one. ThreadSubjectType is the closed list of what a
 * conversation may be about, and the controller resolves the model from it.
 */
class OpenThreadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', Rule::enum(ThreadSubjectType::class)],
            'subject_id' => ['required', 'integer', 'min:1'],
            'body' => ['nullable', 'string', 'min:1', 'max:2000'],
        ];
    }
}
