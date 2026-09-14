<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Privacy;

use App\Models\User;
use App\Modules\Privacy\Contracts\PersonalDataSource;
use App\Modules\Privacy\Support\Anonymiser;
use App\Modules\Privacy\Support\PersonalDataSection;
use App\Modules\Shopping\Models\Cart;
use App\Modules\Shopping\Models\CartItem;
use App\Modules\Shopping\Models\Quotation;
use App\Modules\Shopping\Models\SellerEnquiry;
use App\Modules\Shopping\Models\WishlistItem;

/**
 * What a buyer was thinking about buying.
 *
 * All of it is deleted outright on erasure. None of it is a financial record:
 * a wishlist is an intention, a cart is an unfinished one, and a quotation
 * that was accepted has already become an order — which Orders keeps.
 *
 * Enquiries and quotations carry free text a buyer wrote to a seller, so they
 * go too. The seller keeps nothing that names the buyer once this has run,
 * because the thread on their side belongs to Messaging and is erased there.
 */
class ShoppingPersonalData implements PersonalDataSource
{
    public function key(): string
    {
        return 'shopping';
    }

    /**
     * @return array<int, PersonalDataSection>
     */
    public function export(User $user): array
    {
        return [
            PersonalDataSection::make(
                'Your wishlist',
                WishlistItem::query()
                    ->where('user_id', $user->getKey())
                    ->with('product:id,name')
                    ->get()
                    ->map(static fn (WishlistItem $item): array => [
                        'Listing' => $item->product->name,
                        'Price when saved' => $item->price_ngwee_at_save,
                        'Saved on' => $item->created_at?->toDateTimeString(),
                    ])->all(),
                'Listings you saved. Prices are in ngwee — 100 ngwee to the kwacha.',
            ),

            PersonalDataSection::make(
                'Your cart',
                Cart::query()
                    ->where('user_id', $user->getKey())
                    ->with('items.product:id,name')
                    ->get()
                    ->flatMap(static fn (Cart $cart): array => $cart->items
                        ->map(static fn (CartItem $item): array => [
                            'Listing' => $item->product->name,
                            'Quantity' => $item->quantity,
                            'Unit price' => $item->unit_price_ngwee,
                            'Added on' => $item->created_at?->toDateTimeString(),
                        ])->all())->all(),
                'What is in your cart right now.',
            ),

            PersonalDataSection::make(
                'Your price requests',
                Quotation::query()
                    ->where('user_id', $user->getKey())
                    ->with(['seller:id,business_name', 'product:id,name'])
                    ->latest('id')
                    ->get()
                    ->map(static fn (Quotation $quotation): array => [
                        'Shop' => $quotation->seller->business_name,
                        'Listing' => $quotation->product->name,
                        'Quantity' => $quotation->quantity,
                        'What you wrote' => $quotation->message,
                        'Status' => $quotation->status->value,
                        'Price quoted' => $quotation->quoted_unit_price_ngwee,
                        'Asked on' => $quotation->created_at?->toDateTimeString(),
                    ])->all(),
                'Prices you asked sellers for, and what they said.',
            ),

            PersonalDataSection::make(
                'Your enquiries to sellers',
                SellerEnquiry::query()
                    ->where('user_id', $user->getKey())
                    ->with(['seller:id,business_name', 'product:id,name'])
                    ->latest('id')
                    ->get()
                    ->map(static fn (SellerEnquiry $enquiry): array => [
                        'Shop' => $enquiry->seller->business_name,
                        /* Nullable: an enquiry may be about the shop rather than a listing. */
                        'Listing' => $enquiry->product?->name,
                        'What you wrote' => $enquiry->message,
                        'Sent on' => $enquiry->created_at?->toDateTimeString(),
                    ])->all(),
                'Messages you sent through the "Contact seller" button.',
            ),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function erase(User $user, Anonymiser $anonymiser): array
    {
        $carts = Cart::query()->where('user_id', $user->getKey())->get();

        $cartItems = 0;

        foreach ($carts as $cart) {
            $cartItems += $cart->items()->delete();
        }

        return [
            'wishlist_items' => WishlistItem::query()->where('user_id', $user->getKey())->delete(),
            'cart_items' => $cartItems,
            'carts' => Cart::query()->where('user_id', $user->getKey())->delete(),
            'quotations' => Quotation::query()->where('user_id', $user->getKey())->delete(),
            'seller_enquiries' => SellerEnquiry::query()->where('user_id', $user->getKey())->delete(),
        ];
    }
}
