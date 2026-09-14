<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Support\ThreadParties;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Shopping\Contracts\SellerEnquiryChannel;
use App\Modules\Shopping\Events\SellerEnquired;
use App\Modules\Shopping\Support\EnquiryReference;
use Illuminate\Support\Carbon;

/**
 * "Contact seller", now that Messaging exists.
 *
 * Shopping declared this contract and shipped StoredEnquiryChannel — real
 * rows, a real record, and no replies — precisely so the storefront button
 * could keep its promise before there was anywhere for the seller to answer.
 * This is the implementation it was waiting for: the same call from the same
 * button now opens a thread the shop can reply in, and not one line of
 * Shopping changes.
 *
 * A listing enquiry needs a buyer AND a listing to find its thread, which is
 * why it goes through openWith() rather than openFor(): a listing on its own
 * does not know which buyer is asking.
 */
class MessagingEnquiryChannel implements SellerEnquiryChannel
{
    public function __construct(private readonly ThreadService $threads) {}

    public function send(User $buyer, Seller $seller, string $message, ?Product $product = null): EnquiryReference
    {
        /*
         * With no listing to hang it off, the conversation is about the shop
         * itself — and the shop's own listing-less thread has no subject, so
         * the enquiry goes against the shop's storefront instead. Shopping
         * only ever calls this from a listing page today; the fallback exists
         * so a future "message this shop" button is not a crash.
         */
        $subject = $product ?? $seller;

        $thread = $this->threads->openWith(
            $subject,
            ThreadParties::buyerAndSeller($buyer, $seller),
        );

        $posted = $this->threads->post($thread, $buyer, $message);

        $reference = new EnquiryReference(
            id: (string) $thread->getKey(),
            message: $posted->body,
            sentAt: Carbon::instance($posted->created_at ?? now()),
        );

        /*
         * Still dispatched, and still carrying the reference rather than a
         * model. Shopping's event was written to survive exactly this
         * handover — anything listening for "a buyer wrote to a shop" should
         * not have to care that the row behind it is now a thread.
         */
        SellerEnquired::dispatch($reference);

        return $reference;
    }

    /**
     * How many messages this buyer has already sent this shop.
     *
     * Counted across every thread between them rather than per listing: the
     * storefront asks so it can say "you have contacted this shop", which is
     * a fact about the shop.
     */
    public function countBetween(User $buyer, Seller $seller): int
    {
        return Message::query()
            ->where('user_id', $buyer->getKey())
            ->whereHas('thread', static fn ($thread) => $thread->where('seller_id', $seller->getKey()))
            ->count();
    }
}
