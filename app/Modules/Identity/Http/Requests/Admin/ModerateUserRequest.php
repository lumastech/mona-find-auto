<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests\Admin;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Stringable;

/**
 * Any staff action against an account.
 *
 * The reason is required and has a floor on its length, because "spam" in an
 * audit row is no use to the person who has to review the decision later, or
 * to the account holder appealing it.
 */
class ModerateUserRequest extends FormRequest
{
    use ResolvesAuthenticatedUser;

    public function authorize(): bool
    {
        $subject = $this->route('user');

        return $subject instanceof User && $this->user()->can('moderate', $subject);
    }

    /**
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Record why you are taking this action.',
            'reason.min' => 'Give a reason somebody reviewing this later can act on.',
        ];
    }

    public function reason(): string
    {
        return $this->string('reason')->trim()->toString();
    }
}
