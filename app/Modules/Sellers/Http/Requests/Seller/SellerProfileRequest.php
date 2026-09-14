<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Requests\Seller;

use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Support\SellerFieldRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Stringable;

/**
 * A seller editing their own shop.
 *
 * The business type is not editable here. A shop that becomes a car breaker
 * changes what every one of its listings is badged as, so that is a
 * conversation with MonaFind rather than a dropdown.
 */
class SellerProfileRequest extends FormRequest
{
    use SellerFieldRules;

    public function authorize(): bool
    {
        $seller = $this->seller();

        return $seller !== null && $this->user()?->can('manage', $seller) === true;
    }

    /**
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    public function rules(): array
    {
        $seller = $this->seller();

        return $this->businessStepRules($seller?->type, $seller?->getKey());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->sellerFieldMessages();
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->normaliseSellerPhones($this->all()));
    }

    public function seller(): ?Seller
    {
        return $this->user()?->seller;
    }
}
