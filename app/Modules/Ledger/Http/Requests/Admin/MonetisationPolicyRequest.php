<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Requests\Admin;

use App\Modules\Ledger\Enums\CommissionType;
use App\Support\Money\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The terms an administrator is putting sellers on.
 *
 * Percentages are validated as decimal strings with at most two places,
 * because that is what Money::percentage() parses exactly. Accepting three
 * would mean silently rounding somebody's 7.125% and charging a rate nobody
 * agreed to.
 *
 * Money fields are typed in kwacha and converted with Money::ofKwacha, which
 * reads "12.50" exactly rather than through a float.
 */
class MonetisationPolicyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'commission_type' => ['required', Rule::enum(CommissionType::class)],
            'commission_percent' => [
                'required_if:commission_type,'.CommissionType::Percentage->value,
                'nullable',
                'regex:/^\d{1,3}(\.\d{1,2})?$/',
            ],
            'commission_flat' => [
                'required_if:commission_type,'.CommissionType::Flat->value,
                'nullable',
                'numeric',
                'min:0',
            ],
            'addon_fee' => ['nullable', 'numeric', 'min:0'],
            'referral_fee_percent' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'commission_percent.regex' => __('Use at most two decimal places, e.g. 7.50.'),
            'referral_fee_percent.regex' => __('Use at most two decimal places, e.g. 1.25.'),
        ];
    }

    /**
     * The attributes as the model wants them.
     *
     * @return array<string, mixed>
     */
    public function policyAttributes(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'description' => $this->validated('description'),
            'commission_type' => CommissionType::from((string) $this->validated('commission_type')),
            'commission_percent' => (string) ($this->validated('commission_percent') ?? '0.00'),
            'commission_flat_ngwee' => $this->kwacha('commission_flat')->ngwee,
            'addon_fee_ngwee' => $this->kwacha('addon_fee')->ngwee,
            'referral_fee_percent' => (string) ($this->validated('referral_fee_percent') ?? '0.00'),
            'is_default' => (bool) $this->validated('is_default', false),
            'is_active' => (bool) $this->validated('is_active', true),
        ];
    }

    private function kwacha(string $field): Money
    {
        $value = $this->validated($field);

        return $value === null || $value === '' ? Money::zero() : Money::ofKwacha((string) $value);
    }
}
