<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Orders\Services\FlatRateDeliveryFee;
use App\Modules\Orders\Support\DeliveryAddress;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;

/**
 * What a seller charges to send a part somewhere.
 *
 * Release 1 has exactly one implementation and it is a flat rate per shop.
 * The interface exists anyway, because the two things that come next —
 * per-zone pricing and distance-based pricing — are the same question asked
 * with more inputs, and the difference between adding them behind this and
 * adding them to a controller is the difference between one new class and a
 * rewrite of checkout.
 *
 * The address is nullable on purpose: a buyer choosing between pickup and
 * delivery has to be shown the delivery charge BEFORE they pick an address,
 * so a strategy that needs one has to answer with its best estimate and let
 * the total settle once the address is known.
 *
 * @see FlatRateDeliveryFee
 */
interface DeliveryFeeStrategy
{
    /**
     * The charge for this seller sending this much to this address.
     */
    public function feeFor(Seller $seller, Money $subtotal, ?DeliveryAddress $address = null): Money;

    /**
     * Whether the fee this strategy quotes can still move once the buyer has
     * chosen an address.
     *
     * A flat rate cannot, and the checkout page says so. A distance-based one
     * can, and the page has to warn the buyer rather than surprise them at
     * the payment step.
     */
    public function isFinalWithoutAddress(): bool;
}
