<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Database\Factories\ProductVariantFactory;
use App\Modules\Inventory\Enums\StockLevel;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What is actually bought: a SKU, a price and a quantity.
 *
 * Every product has at least one of these even when the seller never thought
 * about options — a single-variant listing gets one created for it. The cart,
 * the order line and the ledger then only ever deal with one shape instead of
 * branching on whether a listing happens to have options.
 *
 * The price is VAT-inclusive and held as an integer number of ngwee.
 *
 * `quantity` is written by App\Modules\Inventory\Services\StockLedger and by
 * nothing else. Every change to it is taken under a row lock and recorded in
 * the stock ledger, which is what stops two buyers paying for the same last
 * alternator; a `$variant->quantity = 0; $variant->save()` anywhere else
 * silently opts out of both.
 *
 * @property int $id
 * @property int $product_id
 * @property string $sku
 * @property string|null $name
 * @property Money $price
 * @property int $quantity
 * @property int|null $low_stock_threshold Null follows the platform default.
 * @property Carbon|null $low_stock_alerted_at
 * @property Carbon|null $out_of_stock_alerted_at
 * @property bool $is_default
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 */
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'quantity' => 'integer',
            'low_stock_threshold' => 'integer',
            'low_stock_alerted_at' => 'datetime',
            'out_of_stock_alerted_at' => 'datetime',
            'is_default' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inStock(): bool
    {
        return $this->quantity > 0;
    }

    /**
     * The quantity at or below which this option counts as running out.
     *
     * A seller who never set one follows the platform default, read at call
     * time — so raising the default in admin moves every shop that never had
     * an opinion, and leaves alone every shop that did.
     */
    public function lowStockThreshold(): int
    {
        return $this->low_stock_threshold ?? settings()->integer('stock.low_stock_threshold', 2);
    }

    /**
     * What a buyer is told about availability: In stock, Low stock, or Out of
     * stock. Never a bare number — see StockLevel.
     */
    public function stockLevel(): StockLevel
    {
        return StockLevel::forQuantity($this->quantity, $this->lowStockThreshold());
    }

    public function isLowStock(): bool
    {
        return $this->stockLevel() === StockLevel::LowStock;
    }

    /**
     * What the option is called on screen.
     *
     * A single-variant listing has no option name, so it borrows the
     * listing's — a dropdown reading "(no name)" helps nobody.
     */
    public function displayName(): string
    {
        return $this->name ?? $this->product->name;
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeInStock(Builder $query): void
    {
        $query->where('quantity', '>', 0);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOutOfStock(Builder $query): void
    {
        $query->where('quantity', '<=', 0);
    }

    /**
     * Options at or below their threshold but not yet empty.
     *
     * The COALESCE is what lets one query cover both the sellers who set a
     * threshold and the sellers following the platform default.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeLowStock(Builder $query): void
    {
        $default = settings()->integer('stock.low_stock_threshold', 2);

        $query->where('quantity', '>', 0)
            ->whereRaw('quantity <= COALESCE(low_stock_threshold, ?)', [$default]);
    }

    /**
     * A SKU for a seller who did not supply one.
     *
     * Built from the listing name and a short random tail rather than from
     * the id, because the SKU has to exist before the row does.
     */
    public static function generateSku(string $productName): string
    {
        return Str::of($productName)
            ->slug()
            ->upper()
            ->limit(20, '')
            ->append('-', Str::upper(Str::random(5)))
            ->toString();
    }
}
