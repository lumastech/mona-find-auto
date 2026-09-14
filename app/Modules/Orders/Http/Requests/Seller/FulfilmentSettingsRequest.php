<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * What a shop is willing to do about getting a part to a buyer.
 *
 * The one rule with teeth is the cross-field one: a shop that offers neither
 * collection nor delivery cannot be ordered from at all, and would sit on the
 * storefront looking open while every checkout attempt failed. Caught here
 * rather than at checkout, where it would be the buyer's problem.
 */
class FulfilmentSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'offers_pickup' => ['required', 'boolean'],
            'offers_delivery' => ['required', 'boolean'],
            /* Kwacha in, ngwee stored — the controller converts through Money. */
            'delivery_fee' => ['required_if:offers_delivery,true', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'delivery_note' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->boolean('offers_pickup') && ! $this->boolean('offers_delivery')) {
                    $validator->errors()->add(
                        'offers_pickup',
                        __('Choose at least one: buyers must be able to collect from you or have parts delivered.'),
                    );
                }
            },
        ];
    }
}
