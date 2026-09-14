<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests\Storefront;

use App\Modules\Orders\Enums\FulfilmentMethod;
use App\Modules\Orders\Enums\PaymentMethod;
use App\Modules\Orders\Support\CheckoutSelection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The checkout post: one selection per shop, one payment method, one consent.
 *
 * The acceptance fields are validated as hard requirements rather than
 * treated as a checkbox. `accepted` must be true and `accepted_policies` must
 * carry the id and version of every document the buyer was shown — the
 * service then checks those against what is current and refuses if the seller
 * republished mid-checkout. Consent recorded for text nobody read is worse
 * than no consent at all, so it is easier to refuse here than to explain
 * later.
 */
class PlaceOrderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],

            'selections' => ['required', 'array', 'min:1'],
            'selections.*.seller_id' => ['required', 'integer', 'exists:sellers,id'],
            'selections.*.fulfilment_method' => ['required', Rule::enum(FulfilmentMethod::class)],

            /*
             * Scoped to this buyer's own address book. Without the scope an
             * address id in a form post is a way to read a stranger's address
             * back off an order.
             */
            'selections.*.user_address_id' => [
                'nullable',
                'integer',
                Rule::exists('user_addresses', 'id')->where('user_id', $this->user()?->getKey()),
            ],
            'selections.*.delivery_instructions' => ['nullable', 'string', 'max:500'],

            'selections.*.accepted' => ['required', 'accepted'],
            'selections.*.accepted_policies' => ['present', 'array'],
            'selections.*.accepted_policies.*.policy_id' => ['required', 'integer'],
            'selections.*.accepted_policies.*.version' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'selections.*.accepted.accepted' => __('You have to accept the seller\'s terms before you can order.'),
            'selections.*.user_address_id.exists' => __('Choose a delivery address from your address book.'),
        ];
    }

    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::from((string) $this->validated('payment_method'));
    }

    /**
     * The buyer's choices, keyed by seller.
     *
     * @return array<int, CheckoutSelection>
     */
    public function selections(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->validated('selections');

        return CheckoutSelection::collect($rows);
    }
}
