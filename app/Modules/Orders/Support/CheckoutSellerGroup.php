<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Enums\FulfilmentMethod;
use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Models\SellerPolicy;
use App\Modules\Shopping\Support\CartSellerGroup;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * One shop's part of a checkout: what is being bought, how it can be got, and
 * on whose terms.
 *
 * Checkout is per shop rather than per cart for the same reason the cart page
 * is: four shops means four dispatches, four sets of policies and four
 * fulfilment decisions, and a single "delivery method" for the whole basket
 * would be describing a delivery nobody is going to make.
 *
 * The policies carried here are the CURRENT versions, loaded once when the
 * page is built. They are shown in the acceptance modal and their ids and
 * version numbers are what gets written onto the order — which is why the
 * service re-reads them at placement time and refuses if a seller published
 * something new in between.
 */
final readonly class CheckoutSellerGroup
{
    /**
     * @param  Collection<string, SellerPolicy>  $policies  Current versions, keyed by type value.
     * @param  array<int, FulfilmentMethod>  $availableMethods
     */
    public function __construct(
        public CartSellerGroup $cart,
        public Seller $seller,
        public Collection $policies,
        public array $availableMethods,
        public Money $deliveryFee,
        public bool $deliveryFeeIsFinal,
    ) {}

    public function subtotal(): Money
    {
        return $this->cart->subtotal();
    }

    /**
     * What this shop's part comes to under a given fulfilment method.
     */
    public function totalFor(FulfilmentMethod $method): Money
    {
        return $method === FulfilmentMethod::Delivery
            ? $this->subtotal()->plus($this->deliveryFee)
            : $this->subtotal();
    }

    public function supports(FulfilmentMethod $method): bool
    {
        return in_array($method, $this->availableMethods, true);
    }

    /**
     * The method the page should open on.
     *
     * Whatever the shop can actually do, preferring collection: it costs the
     * buyer nothing and is what most Zambian parts shops offer.
     */
    public function defaultMethod(): FulfilmentMethod
    {
        return $this->supports(FulfilmentMethod::Pickup)
            ? FulfilmentMethod::Pickup
            : FulfilmentMethod::Delivery;
    }

    public function policy(PolicyType $type): ?SellerPolicy
    {
        return $this->policies->get($type->value);
    }

    /**
     * The policy ids and versions the buyer is being shown, in the shape the
     * acceptance record stores.
     *
     * @return array<int, array{policy_id: int, type: string, version: int, effective_from: string}>
     */
    public function policyFingerprint(): array
    {
        return $this->policies
            ->map(static fn (SellerPolicy $policy): array => [
                'policy_id' => $policy->getKey(),
                'type' => $policy->type->value,
                'version' => $policy->version,
                'effective_from' => $policy->effective_from->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
