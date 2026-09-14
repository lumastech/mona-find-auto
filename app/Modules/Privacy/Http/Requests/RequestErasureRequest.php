<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Asking for an account to be erased.
 *
 * ## Why this asks for the password
 *
 * Everything else on the settings screen is reversible. This is not — after
 * the grace period there is nothing left to restore — so it is the one action
 * on the platform that re-proves who is holding the session. A borrowed
 * laptop should not be able to delete somebody's account and their history
 * with every seller they have ever bought from.
 *
 * The reason is optional and free text. It is the only place a person gets to
 * say why they are leaving, and making it mandatory would turn an exercise of
 * a legal right into a form with a required field.
 */
class RequestErasureRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'current_password'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'confirm' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.current_password' => 'That password is not correct.',
            'confirm.accepted' => 'Please confirm that you understand this cannot be undone.',
        ];
    }
}
