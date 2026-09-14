<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests\Storefront;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Modules\Identity\Models\UserAddress;
use App\Modules\Identity\Rules\ZambianMobileNumber;
use App\Modules\Identity\Support\AccountFieldRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Stringable;

/**
 * Creating or editing one saved delivery address.
 */
class AddressRequest extends FormRequest
{
    use AccountFieldRules, ResolvesAuthenticatedUser;

    public function authorize(): bool
    {
        $address = $this->route('address');

        /* Creating needs only an account; editing needs to own the address. */
        return $address instanceof UserAddress
            ? $this->user()->can('update', $address)
            : true;
    }

    /**
     * The recipient's number is normalised the same way the account's is, so
     * a courier always gets a dialable number.
     */
    protected function prepareForValidation(): void
    {
        $this->merge($this->normalisePhone($this->all(), ['recipient_phone']));
    }

    /**
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:60'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_phone' => ['required', 'string', new ZambianMobileNumber],
            ...$this->addressRules(),
            'directions' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'place_id' => ['nullable', 'string', 'max:255'],
            'formatted_address' => ['nullable', 'string', 'max:255'],
            'is_default' => ['boolean'],
        ];
    }
}
