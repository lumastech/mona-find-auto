<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests\Api;

use App\Modules\Identity\Enums\MobileNetwork;
use App\Modules\Identity\Support\ZambianPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Starting a USSD push from the mobile app.
 *
 * The number is normalised to E.164 before it reaches the gateway, because
 * Zambian buyers type 0971234567, +260971234567 and 260971234567 with equal
 * conviction and Lenco accepts exactly one of them.
 */
class CollectMobileMoneyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:20'],
            'network' => ['required', 'string', Rule::enum(MobileNetwork::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => __('Enter the mobile money number to charge.'),
            'network.required' => __('Choose a mobile money network.'),
        ];
    }

    /**
     * The number in the form the gateway wants.
     */
    public function phone(): string
    {
        $typed = (string) $this->string('phone');

        return ZambianPhone::tryParse($typed)?->e164() ?? $typed;
    }

    public function network(): string
    {
        return (string) $this->string('network');
    }

    /**
     * Refuse a number that is not a Zambian mobile at all, rather than
     * letting the gateway reject it a round trip later.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (ZambianPhone::tryParse((string) $this->string('phone')) === null) {
                $validator->errors()->add('phone', __('Enter a valid Zambian mobile number.'));
            }
        });
    }
}
