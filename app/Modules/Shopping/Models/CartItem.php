<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Shopping\Database\Factories\CartItemFactory;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a cart: a variant, a quantity and a price.
 *
 * `unit_price_ngwee` is the price the buyer was last shown, which is not
 * necessarily the price the listing carries now. Keeping both is what lets
 * the cart say "this went up by K50 since you added it" rather than silently
 * charging the difference at checkout.
 *
 * A line carrying a `quotation_id` is different in kind: its price is an
 * offer the seller made, not a snapshot of a shelf price, and it stays put
 * while the quote is valid. That id travels on to the order line, so a
 * payment can always be traced back to the offer that produced it.
 *
 * @property int $id
 * @property int $cart_id
 * @property int $product_variant_id
 * @property int $product_id
 * @property int $seller_id
 * @property int $quantity
 * @property Money $unit_price_ngwee
 * @property int|null $quotation_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Cart $cart
 * @property-read ProductVariant $variant
 * @property-read Product $product
 * @property-read Seller $seller
 * @property-read Quotation|null $quotation
 */
class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_ngwee' => MoneyCast::class,
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * Whether this line's price is a negotiated offer rather than a shelf
     * price.
     */
    public function isQuoted(): bool
    {
        return $this->quotation_id !== null;
    }

    /**
     * Quantity times the line's own unit price.
     */
    public function lineTotal(): Money
    {
        return $this->unit_price_ngwee->times($this->quantity);
    }
}
