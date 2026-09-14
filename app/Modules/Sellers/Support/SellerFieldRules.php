<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Support;

use App\Modules\Identity\Enums\MobileNetwork;
use App\Modules\Identity\Models\City;
use App\Modules\Identity\Models\Province;
use App\Modules\Identity\Rules\CityInProvince;
use App\Modules\Identity\Rules\ZambianMobileNumber;
use App\Modules\Identity\Support\ZambianPhone;
use App\Modules\Sellers\Enums\DocumentType;
use App\Modules\Sellers\Enums\PayoutMethod;
use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Enums\SellerType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Stringable;

/**
 * What a valid seller looks like.
 *
 * The sign-up wizard, the seller's own profile editor and the JSON API all
 * describe the same business, so they share one definition rather than three
 * that drift.
 */
trait SellerFieldRules
{
    /**
     * Step one: what kind of business this is.
     *
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    protected function typeStepRules(): array
    {
        return [
            'type' => ['required', Rule::enum(SellerType::class)],
        ];
    }

    /**
     * Step two: who the business is and where it trades from.
     *
     * The registration number is optional here on purpose — plenty of real
     * shops start selling before their PACRA certificate arrives — but it is
     * mandatory before the Verified badge is granted.
     *
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    protected function businessStepRules(?SellerType $type = null, ?int $sellerId = null): array
    {
        return [
            'business_name' => ['required', 'string', 'min:2', 'max:150'],
            'registration_number' => ['nullable', 'string', 'max:60', $this->uniqueRegistrationNumber($sellerId)],
            'description' => ['nullable', 'string', 'max:2000'],

            'province_id' => ['required', 'integer', Rule::exists(Province::class, 'id')],
            'city_id' => ['required', 'integer', Rule::exists(City::class, 'id'), new CityInProvince],
            'street' => ['required', 'string', 'max:255'],
            'plot_number' => ['nullable', 'string', 'max:60'],

            /* The map pin is optional, but a half-dropped pin is not a pin. */
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'place_id' => ['nullable', 'string', 'max:255'],
            'formatted_address' => ['nullable', 'string', 'max:255'],

            'phone' => ['required', 'string', new ZambianMobileNumber],
            'email' => ['required', 'string', 'email', 'max:255'],
            'contact_person' => ['required', 'string', 'max:150'],

            'opening_hours' => ['nullable', 'array'],
            'opening_hours.*.open' => ['nullable', 'date_format:H:i', 'required_with:opening_hours.*.close'],
            'opening_hours.*.close' => ['nullable', 'date_format:H:i', 'required_with:opening_hours.*.open', 'after:opening_hours.*.open'],

            ...$this->capacityRulesFor($type),
        ];
    }

    /**
     * Step three: the policies buyers accept at checkout.
     *
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    protected function policiesStepRules(): array
    {
        $rules = [];

        foreach (PolicyType::cases() as $type) {
            $required = in_array($type, PolicyType::required(), true);

            $rules["policies.{$type->value}"] = [$required ? 'required' : 'nullable', 'string', 'min:30', 'max:20000'];
        }

        return $rules;
    }

    /**
     * Step four: where payouts land.
     *
     * The two methods ask for different fields, so each set is required only
     * when its method was chosen.
     *
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    protected function payoutStepRules(): array
    {
        $bank = 'required_if:method,'.PayoutMethod::Bank->value;
        $mobile = 'required_if:method,'.PayoutMethod::MobileMoney->value;

        return [
            'method' => ['required', Rule::enum(PayoutMethod::class)],
            'label' => ['nullable', 'string', 'max:60'],

            'beneficiary_name' => [$bank, 'nullable', 'string', 'max:150'],
            'account_number' => [$bank, 'nullable', 'string', 'max:34', 'regex:/^[A-Za-z0-9]+$/'],
            'bank_code' => [$bank, 'nullable', 'string', 'max:16'],
            'bank_branch' => ['nullable', 'string', 'max:150'],
            'bank_address' => ['nullable', 'string', 'max:255'],
            'swift_code' => ['nullable', 'string', 'max:11', 'regex:/^[A-Za-z0-9]{8}([A-Za-z0-9]{3})?$/'],
            'tpin' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/'],

            'mobile_number' => [$mobile, 'nullable', 'string', new ZambianMobileNumber],
            /* Zamtel has no merchant payout wallet, so payouts are MTN or Airtel only. */
            'network' => [$mobile, 'nullable', Rule::in([MobileNetwork::Mtn->value, MobileNetwork::Airtel->value])],

            'is_default' => ['boolean'],
        ];
    }

    /**
     * Step five: the paperwork.
     *
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    protected function documentStepRules(): array
    {
        return [
            'document_type' => ['required', Rule::enum(DocumentType::class)],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function sellerFieldMessages(): array
    {
        return [
            'business_name.required' => 'Every seller trades under a business name, even a one-person yard.',
            'account_number.regex' => 'An account number is letters and digits only.',
            'swift_code.regex' => 'A SWIFT code is 8 or 11 letters and digits.',
            'tpin.regex' => 'A TPIN is digits only.',
            'network.in' => 'Mobile-money payouts go to MTN or Airtel.',
            'opening_hours.*.close.after' => 'Closing time has to be after opening time.',
            'policies.delivery.min' => 'Say enough about delivery that a buyer knows what to expect.',
            'policies.refund.min' => 'Say enough about refunds that a buyer knows what to expect.',
            'policies.warranty.min' => 'Say enough about your warranty that a buyer knows what to expect.',
        ];
    }

    /**
     * A registration number identifies one business at PACRA, so two sellers
     * claiming the same one is either a typo or a fraud; both are worth
     * stopping at the form.
     */
    private function uniqueRegistrationNumber(?int $sellerId): ValidationRule|Stringable
    {
        $rule = Rule::unique('sellers', 'registration_number');

        return $sellerId === null ? $rule : $rule->ignore($sellerId);
    }

    /**
     * Businesses that work on vehicles are asked about the site's capacity;
     * a parts retailer is not asked at all.
     *
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    private function capacityRulesFor(?SellerType $type): array
    {
        if ($type === null || ! $type->hasWorkshopCapacity()) {
            return [];
        }

        return [
            'bay_count' => ['required', 'integer', 'min:1', 'max:500'],
        ];
    }

    /**
     * Rewrite phone-shaped input into E.164 before it is validated or stored.
     *
     * @param  array<string, mixed>  $input
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    protected function normaliseSellerPhones(array $input, array $fields = ['phone']): array
    {
        foreach ($fields as $field) {
            $value = $input[$field] ?? null;

            if (is_string($value) && ($parsed = ZambianPhone::tryParse($value)) !== null) {
                $input[$field] = $parsed->e164();
            }
        }

        return $input;
    }
}
