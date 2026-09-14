<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Services;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Shopping\Enums\CartLineIssue;
use App\Modules\Shopping\Exceptions\ListingNotPurchasable;
use App\Modules\Shopping\Models\Cart;
use App\Modules\Shopping\Models\CartItem;
use App\Modules\Shopping\Models\Quotation;
use App\Modules\Shopping\Support\CartLine;
use App\Modules\Shopping\Support\CartSellerGroup;
use App\Modules\Shopping\Support\CartView;
use App\Modules\Shopping\Support\RemovedCartLine;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * The cart: what a buyer has chosen, and whether they can still have it.
 *
 * The important thing this class does is not adding lines. It is view(),
 * which re-reads every listing behind the cart on every single read and
 * reports what has moved. A MonaFind cart routinely sits for days — buyers
 * assemble a repair across several shops before finding the money — and in
 * that time sellers raise prices, sell the last one over the counter, and
 * have listings hidden for going unconfirmed. Discovering any of that at the
 * payment page is discovering it too late.
 *
 * So the cart is authoritative about nothing except quantity and intent. The
 * price shown is the seller's price now; the stored one is kept only so the
 * buyer can be told it changed. The single exception is a line from an
 * accepted quote, where the seller made a promise with a date on it and the
 * quoted price governs until that date passes.
 */
class CartService
{
    /** More than this of one part is a wholesale order, and belongs in an RFQ. */
    public const MAX_LINE_QUANTITY = 999;

    /**
     * This buyer's cart, created on first use.
     */
    public function for(User $user): Cart
    {
        return Cart::query()->firstOrCreate(['user_id' => $user->getKey()]);
    }

    /**
     * Put an option in the cart.
     *
     * Adding something already there increases the quantity rather than
     * making a second line — except for a quoted line, which is its own line
     * at its own price. The refusals are deliberate: a listing nobody may see
     * and a shelf with nothing on it are both dead ends, and letting them
     * into the cart only moves the disappointment to checkout.
     *
     * @throws ListingNotPurchasable
     */
    public function add(User $user, ProductVariant $variant, int $quantity = 1, ?Quotation $quotation = null): CartItem
    {
        $variant->loadMissing('product.seller');
        $product = $variant->product;

        if (! $product->isVisibleToBuyers()) {
            throw ListingNotPurchasable::unavailable($product);
        }

        if (! $variant->inStock()) {
            throw ListingNotPurchasable::outOfStock($product);
        }

        $cart = $this->for($user);
        $quantity = $this->clamp($quantity, $variant->quantity);

        return DB::transaction(function () use ($cart, $variant, $product, $quantity, $quotation): CartItem {
            $existing = CartItem::query()
                ->where('cart_id', $cart->getKey())
                ->where('product_variant_id', $variant->getKey())
                ->where('quotation_id', $quotation?->getKey())
                ->first();

            if ($existing !== null) {
                $existing->update([
                    'quantity' => $this->clamp($existing->quantity + $quantity, $variant->quantity),
                    /* A quoted line keeps its promised price; a shelf line takes today's. */
                    'unit_price_ngwee' => $quotation !== null
                        ? $existing->unit_price_ngwee
                        : $variant->price,
                ]);

                return $existing;
            }

            return CartItem::query()->create([
                'cart_id' => $cart->getKey(),
                'product_variant_id' => $variant->getKey(),
                'product_id' => $product->getKey(),
                'seller_id' => $product->seller_id,
                'quantity' => $quantity,
                'unit_price_ngwee' => $quotation?->effectiveUnitPrice() ?? $variant->price,
                'quotation_id' => $quotation?->getKey(),
            ]);
        });
    }

    /**
     * Change how many of a line the buyer wants.
     *
     * Clamped to what the seller actually has rather than refused, so a buyer
     * asking for six of the four remaining gets four and is told, instead of
     * a validation error and a form to fill in again.
     */
    public function updateQuantity(CartItem $item, int $quantity): CartItem
    {
        if ($quantity < 1) {
            $this->remove($item);

            return $item;
        }

        $item->loadMissing('variant');

        $item->update(['quantity' => $this->clamp($quantity, $item->variant->quantity)]);

        return $item;
    }

    public function remove(CartItem $item): void
    {
        $item->delete();
    }

    public function clear(User $user): void
    {
        $this->for($user)->items()->delete();
    }

    /**
     * The cart as it should be shown right now.
     *
     * Every line is checked against its listing, dead lines are dropped, and
     * what survives is grouped by shop with each shop's own subtotal —
     * because that is what the buyer will actually pay, to whom, and in how
     * many separate dispatches.
     */
    public function view(User $user): CartView
    {
        $cart = $this->for($user);

        $items = $cart->items()
            ->with(['variant.product.seller.city', 'product.media', 'seller.city', 'quotation'])
            ->get();

        /** @var array<int, CartLine> $lines */
        $lines = [];
        /** @var array<int, RemovedCartLine> $removed */
        $removed = [];

        foreach ($items as $item) {
            $line = $this->revalidate($item);

            if ($line === null) {
                $removed[] = new RemovedCartLine(
                    productId: $item->product_id,
                    productName: $item->product->name,
                    productSlug: $item->product->slug,
                    sellerName: $item->seller->business_name,
                );

                $this->remove($item);

                continue;
            }

            $lines[] = $line;
        }

        return new CartView($cart, $this->groupBySeller($lines), $removed);
    }

    /**
     * How many individual parts are in the cart — the header badge.
     */
    public function count(?User $user): int
    {
        if ($user === null) {
            return 0;
        }

        return (int) CartItem::query()
            ->whereIn('cart_id', Cart::query()->select('id')->where('user_id', $user->getKey()))
            ->sum('quantity');
    }

    /**
     * Check one line against the listing behind it.
     *
     * Returns null when the line is gone rather than merely changed — an
     * unpublished, hidden or suspended listing is not something the buyer can
     * be shown a new price for.
     *
     * Anything that changed is written back to the row as it is reported, so
     * the notice appears exactly once: the buyer is told the price moved on
     * the visit where it moved, and not on every visit afterwards.
     */
    private function revalidate(CartItem $item): ?CartLine
    {
        $variant = $item->variant;
        $product = $variant->product;

        if (! $product->isVisibleToBuyers()) {
            return null;
        }

        $issues = [];
        $previousPrice = null;
        $requestedQuantity = null;

        $unitPrice = $this->priceFor($item, $variant, $issues);

        if (! $unitPrice->equals($item->unit_price_ngwee)) {
            $previousPrice = $item->unit_price_ngwee;
            $issues[] = CartLineIssue::PriceChanged;
        }

        $quantity = $item->quantity;

        if ($variant->quantity <= 0) {
            $issues[] = CartLineIssue::OutOfStock;
        } elseif ($quantity > $variant->quantity) {
            $requestedQuantity = $quantity;
            $quantity = $variant->quantity;
            $issues[] = CartLineIssue::QuantityReduced;
        }

        /*
         * Write back what we just told the buyer. Without this the same
         * "price changed" notice would reappear on every visit until they
         * paid, and a notice that never goes away is a notice nobody reads.
         */
        if ($previousPrice !== null || $requestedQuantity !== null) {
            $item->forceFill([
                'unit_price_ngwee' => $unitPrice,
                'quantity' => $quantity,
            ])->save();
        }

        return new CartLine(
            item: $item,
            unitPrice: $unitPrice,
            quantity: $quantity,
            issues: $issues,
            previousUnitPrice: $previousPrice,
            requestedQuantity: $requestedQuantity,
        );
    }

    /**
     * What this line costs per unit today.
     *
     * An accepted quote wins over the listing price — that is the whole point
     * of having negotiated — but only while it is still valid. Once the date
     * passes the line falls back to the shelf price and says so, rather than
     * holding a buyer to an offer the seller is no longer making or holding
     * the seller to one they have withdrawn.
     *
     * @param  array<int, CartLineIssue>  $issues
     */
    private function priceFor(CartItem $item, ProductVariant $variant, array &$issues): Money
    {
        $quotation = $item->quotation;

        if ($quotation === null) {
            return $variant->price;
        }

        if ($quotation->hasExpired()) {
            $issues[] = CartLineIssue::QuoteExpired;

            return $variant->price;
        }

        return $quotation->effectiveUnitPrice();
    }

    /**
     * Gather lines into one group per shop, in the order the shops first
     * appear in the cart.
     *
     * @param  array<int, CartLine>  $lines
     * @return array<int, CartSellerGroup>
     */
    private function groupBySeller(array $lines): array
    {
        /** @var array<int, array<int, CartLine>> $bySeller */
        $bySeller = [];

        foreach ($lines as $line) {
            $bySeller[$line->item->seller_id][] = $line;
        }

        return array_values(array_map(
            static fn (array $sellerLines): CartSellerGroup => new CartSellerGroup(
                seller: $sellerLines[0]->item->seller,
                lines: $sellerLines,
            ),
            $bySeller,
        ));
    }

    /**
     * Keep a quantity between one and what the shelf holds.
     */
    private function clamp(int $quantity, int $available): int
    {
        return max(1, min($quantity, $available, self::MAX_LINE_QUANTITY));
    }
}
