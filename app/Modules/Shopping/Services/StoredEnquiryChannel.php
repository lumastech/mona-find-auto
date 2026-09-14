<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Shopping\Contracts\SellerEnquiryChannel;
use App\Modules\Shopping\Events\SellerEnquired;
use App\Modules\Shopping\Models\SellerEnquiry;
use App\Modules\Shopping\Support\EnquiryReference;
use Illuminate\Support\Carbon;

/**
 * "Contact seller", until Messaging exists.
 *
 * A stub in the sense that there are no replies, no attachments and no
 * thread view — but not in the sense of doing nothing. The message is stored,
 * the seller can see it, and both sides have a record. A button that
 * pretended to send would be worse than no button.
 */
class StoredEnquiryChannel implements SellerEnquiryChannel
{
    public function send(User $buyer, Seller $seller, string $message, ?Product $product = null): EnquiryReference
    {
        $enquiry = SellerEnquiry::query()->create([
            'user_id' => $buyer->getKey(),
            'seller_id' => $seller->getKey(),
            'product_id' => $product?->getKey(),
            'message' => $message,
        ]);

        $reference = new EnquiryReference(
            id: (string) $enquiry->getKey(),
            message: $enquiry->message,
            sentAt: Carbon::instance($enquiry->created_at ?? now()),
        );

        SellerEnquired::dispatch($reference);

        return $reference;
    }

    public function countBetween(User $buyer, Seller $seller): int
    {
        return SellerEnquiry::query()
            ->where('user_id', $buyer->getKey())
            ->where('seller_id', $seller->getKey())
            ->count();
    }
}
