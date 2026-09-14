<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Contracts;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Shopping\Support\EnquiryReference;

/**
 * How "Contact seller" reaches a seller.
 *
 * Declared by the module that needs it rather than by the one that will
 * eventually answer it — the same arrangement Search uses for seller
 * reputation. Shopping knows it has to put a buyer in touch with a shop; it
 * does not need to know that a thread, a notification and a moderation queue
 * are involved, and Messaging is not built yet.
 *
 * StoredEnquiryChannel is the implementation until it is: real rows, a real
 * record both sides can point at, and no replies. When Messaging arrives it
 * binds its own and the storefront does not change.
 */
interface SellerEnquiryChannel
{
    /**
     * Send a buyer's message to a seller and hand back something the caller
     * can show them.
     */
    public function send(User $buyer, Seller $seller, string $message, ?Product $product = null): EnquiryReference;

    /**
     * How many messages this buyer already has with this seller — enough for
     * the storefront to say "you have contacted this shop" without knowing
     * what a thread is.
     */
    public function countBetween(User $buyer, Seller $seller): int;
}
