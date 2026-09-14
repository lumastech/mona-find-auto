<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Http\Requests;

use App\Modules\Privacy\Enums\ConsentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changing one optional consent.
 *
 * The rule on `type` accepts only withdrawable consents. Withdrawing the
 * terms or the privacy notice is not a consent change — it is a request to
 * close the account — so an attempt to post one of those is a validation
 * failure rather than a silently ignored field.
 */
class UpdateConsentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => [
                'required',
                Rule::enum(ConsentType::class)->only($this->withdrawableTypes()),
            ],
            'granted' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.*' => 'That agreement cannot be withdrawn on its own. Delete your account instead.',
        ];
    }

    public function consentType(): ConsentType
    {
        return ConsentType::from((string) $this->input('type'));
    }

    /**
     * @return array<int, ConsentType>
     */
    private function withdrawableTypes(): array
    {
        return array_values(array_filter(
            ConsentType::cases(),
            static fn (ConsentType $type): bool => $type->isWithdrawable(),
        ));
    }
}
