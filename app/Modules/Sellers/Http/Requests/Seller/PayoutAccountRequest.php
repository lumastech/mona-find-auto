<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Requests\Seller;

use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Support\SellerFieldRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Stringable;

/**
 * Adding a payout account.
 *
 * The shape of the form is only half the check: whether the account actually
 * exists is settled by the gateway in PayoutAccountService, and a lookup that
 * comes back empty stops the save the same way a validation failure does.
 */
class PayoutAccountRequest extends FormRequest
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
        return $this->payoutStepRules();
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
        $this->merge($this->normaliseSellerPhones($this->all(), ['mobile_number']));
    }

    /**
     * The business this account belongs to: the applicant's own, whether they
     * are mid-wizard or managing an existing shop.
     */
    public function seller(): ?Seller
    {
        return $this->user()?->seller;
    }
}
