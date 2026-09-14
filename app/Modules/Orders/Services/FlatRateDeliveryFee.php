<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\DeliveryFeeStrategy;
use App\Modules\Orders\Support\DeliveryAddress;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;

/**
 * One charge per shop, whatever is in the order and wherever it goes.
 *
 * Release 1 pricing, and the right shape for it. Zambian parts sellers
 * overwhelmingly quote a single town-wide figure or nothing at all, and a
 * distance table nobody fills in is worse than a flat rate everybody
 * understands.
 *
 * Because the fee does not depend on the address, the checkout page can show
 * a final total before the buyer has chosen one — which is what
 * isFinalWithoutAddress() tells it. The distance-based strategy that follows
 * in 1.x will answer false there, and the page already knows to warn instead
 * of promising.
 */
class FlatRateDeliveryFee implements DeliveryFeeStrategy
{
    public function feeFor(Seller $seller, Money $subtotal, ?DeliveryAddress $address = null): Money
    {
        return Money::ofNgwee((int) $seller->delivery_fee_ngwee->ngwee);
    }

    public function isFinalWithoutAddress(): bool
    {
        return true;
    }
}
