<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Orders\Database\Factories\OrderItemFactory;
use App\Modules\Shopping\Models\Quotation;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of one seller's order.
 *
 * The descriptive columns are copies, not joins. A receipt has to keep saying
 * what it said the day it was issued even after the seller has renamed the
 * listing, corrected the SKU or re-graded the part — and a listing taken down
 * entirely must not blank out somebody's order history.
 *
 * @property int $id
 * @property int $order_id
 * @property int $product_variant_id
 * @property int $product_id
 * @property int|null $quotation_id
 * @property string $product_name
 * @property string|null $variant_name
 * @property string|null $sku
 * @property Condition|null $condition
 * @property InspectionStatus|null $inspection_status
 * @property Money $unit_price_ngwee
 * @property int $quantity
 * @property Money $total_ngwee
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 * @property-read ProductVariant $variant
 * @property-read Product $product
 * @property-read Quotation|null $quotation
 */
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'condition' => Condition::class,
            'inspection_status' => InspectionStatus::class,
            'unit_price_ngwee' => MoneyCast::class,
            'total_ngwee' => MoneyCast::class,
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
     * The offer this price came from, when it did not come off a shelf.
     *
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * Whether this price was negotiated rather than listed.
     */
    public function wasQuoted(): bool
    {
        return $this->quotation_id !== null;
    }

    /**
     * The line as a receipt reads it: "Bosch oil filter — 0451103316 (×2)".
     */
    public function description(): string
    {
        return collect([$this->product_name, $this->variant_name])
            ->filter()
            ->implode(' — ');
    }
}
