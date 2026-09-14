<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Shopping\Events\ListingWishlisted;
use App\Modules\Shopping\Exceptions\ListingNotPurchasable;
use App\Modules\Shopping\Models\CartItem;
use App\Modules\Shopping\Models\WishlistItem;
use Illuminate\Database\Eloquent\Collection;

/**
 * Saving parts for later, and noticing what happens to them.
 *
 * One rule governs the whole class: the snapshot is written once. Saving an
 * item records the price and the stock position at that moment and nothing
 * ever updates them, because they exist only to be compared against — a
 * baseline that follows the listing would report that nothing ever changes,
 * which is the one thing a wishlist must not do.
 *
 * That is also why saving an already-saved listing is a no-op rather than an
 * update. A buyer pressing the heart twice has not re-decided anything, and
 * treating it as a fresh save would throw away the price drop they were about
 * to be told about.
 */
class WishlistService
{
    public function __construct(private readonly CartService $cart) {}

    /**
     * Save a listing, recording what was true at the time.
     *
     * Idempotent by design — see the class note.
     */
    public function add(User $user, Product $product): WishlistItem
    {
        $product->loadMissing('variants');

        $existing = $this->find($user, $product);

        if ($existing !== null) {
            return $existing;
        }

        $item = WishlistItem::query()->create([
            'user_id' => $user->getKey(),
            'product_id' => $product->getKey(),
            'price_ngwee_at_save' => $product->fromPrice(),
            'in_stock_at_save' => $product->hasStock(),
        ]);

        ListingWishlisted::dispatch($item);

        return $item;
    }

    public function remove(User $user, Product $product): void
    {
        WishlistItem::query()
            ->forUser($user)
            ->where('product_id', $product->getKey())
            ->delete();
    }

    /**
     * Save or unsave, and say which it ended up being.
     *
     * The heart is a toggle on screen, so it is a toggle here rather than
     * leaving every caller to check first and race with itself.
     *
     * @return bool Whether the listing is now saved.
     */
    public function toggle(User $user, Product $product): bool
    {
        if ($this->has($user, $product)) {
            $this->remove($user, $product);

            return false;
        }

        $this->add($user, $product);

        return true;
    }

    public function has(User $user, Product $product): bool
    {
        return $this->find($user, $product) !== null;
    }

    /**
     * The listing ids this buyer has saved, for marking hearts on a page of
     * cards.
     *
     * A guest has saved nothing — they are asked to log in when they press
     * the heart, which is a different thing from having an empty wishlist.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, int>
     */
    public function savedIdsAmong(?User $user, array $productIds): array
    {
        if ($user === null || $productIds === []) {
            return [];
        }

        return WishlistItem::query()
            ->forUser($user)
            ->whereIn('product_id', $productIds)
            ->pluck('product_id')
            ->map(static fn (int $id): int => $id)
            ->all();
    }

    /**
     * The listing ids this buyer has saved, newest first.
     *
     * Shared onto every storefront page so that a product card can render a
     * filled heart without Catalog, Search or Sellers having to know that
     * wishlists exist. Capped, because it travels with every page load.
     *
     * @return array<int, int>
     */
    public function savedIds(?User $user, int $limit = 300): array
    {
        if ($user === null) {
            return [];
        }

        return WishlistItem::query()
            ->forUser($user)
            ->latest()
            ->limit($limit)
            ->pluck('product_id')
            ->map(static fn (int $id): int => $id)
            ->all();
    }

    /**
     * This buyer's wishlist, newest first, with everything the page needs to
     * work out what has changed.
     *
     * @return Collection<int, WishlistItem>
     */
    public function forUser(User $user): Collection
    {
        return WishlistItem::query()
            ->forUser($user)
            ->with(['product.seller.city', 'product.variants', 'product.media', 'product.category'])
            ->latest()
            ->get();
    }

    public function count(?User $user): int
    {
        return $user === null ? 0 : WishlistItem::query()->forUser($user)->count();
    }

    /**
     * Move a saved listing into the cart and stop saving it.
     *
     * Which option ends up in the cart is decided here rather than asked of
     * the buyer: a wishlist is kept per listing, so the obvious answer is the
     * seller's default option, and the cheapest one with stock when the
     * default has run out. A buyer who wanted the other one changes it in the
     * cart, which is where options belong anyway.
     *
     * @throws ListingNotPurchasable
     */
    public function moveToCart(User $user, Product $product, int $quantity = 1): CartItem
    {
        $variant = $this->variantToBuy($product);

        if ($variant === null) {
            throw ListingNotPurchasable::outOfStock($product);
        }

        $line = $this->cart->add($user, $variant, $quantity);

        $this->remove($user, $product);

        return $line;
    }

    /**
     * The option a "move to cart" should pick.
     *
     * Prefers the seller's default; falls back to the cheapest option that
     * actually has stock, so a listing whose default sold out is still
     * buyable rather than mysteriously refusing.
     */
    public function variantToBuy(Product $product): ?ProductVariant
    {
        $product->loadMissing('variants');

        $default = $product->variants->first(
            static fn (ProductVariant $variant): bool => $variant->is_default && $variant->inStock(),
        );

        if ($default !== null) {
            return $default;
        }

        return $product->variants
            ->filter(static fn (ProductVariant $variant): bool => $variant->inStock())
            ->sortBy(static fn (ProductVariant $variant): int => $variant->price->ngwee)
            ->first();
    }

    private function find(User $user, Product $product): ?WishlistItem
    {
        return WishlistItem::query()
            ->forUser($user)
            ->where('product_id', $product->getKey())
            ->first();
    }
}
