<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Identity\Models\UserAddress;
use App\Modules\Shopping\Support\CartView;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * Everything the checkout page needs, assembled once.
 *
 * Built fresh on every load, and built off a re-priced cart — CartService
 * re-checks every line against its listing on every read, so a buyer who left
 * the tab open for two days is shown today's prices here rather than
 * discovering them at the payment step.
 *
 * `blocksCheckout` is that check's verdict. A price that moved does not stop
 * the buyer, because they have now been shown it; an empty shelf does.
 */
final readonly class CheckoutView
{
    /**
     * @param  array<int, CheckoutSellerGroup>  $groups
     * @param  Collection<int, UserAddress>  $addresses
     */
    public function __construct(
        public CartView $cart,
        public array $groups,
        public Collection $addresses,
        public PlatformTerms $platformTerms,
    ) {}

    public function itemsTotal(): Money
    {
        return $this->cart->total();
    }

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }

    /**
     * Whether anything in the cart stops the buyer paying at all.
     */
    public function blocksCheckout(): bool
    {
        return $this->cart->blocksCheckout();
    }

    /**
     * The address the form should open on: the buyer's default, else their
     * most recent, else none and the "add an address" form.
     */
    public function defaultAddress(): ?UserAddress
    {
        return $this->addresses->firstWhere('is_default', true) ?? $this->addresses->first();
    }

    public function sellerCount(): int
    {
        return count($this->groups);
    }
}
