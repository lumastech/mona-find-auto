<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Requests\Seller;

use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Support\SellerFieldRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Stringable;

/**
 * Publishing a new version of one policy.
 *
 * There is no "which version am I editing" field: publishing always writes a
 * new version on top of whatever is current, so two people saving at once
 * both get a version rather than one silently overwriting the other.
 */
class SellerPolicyRequest extends FormRequest
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
        return [
            'body' => ['required', 'string', 'min:30', 'max:20000'],
            'effective_from' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.min' => 'Say enough that a buyer knows what to expect.',
            'effective_from.after_or_equal' => 'A policy cannot come into force in the past — buyers have already ordered under the current one.',
        ];
    }

    public function policyType(): PolicyType
    {
        $type = $this->route('type');

        return $type instanceof PolicyType ? $type : PolicyType::from((string) $type);
    }

    public function seller(): ?Seller
    {
        return $this->user()?->seller;
    }
}
