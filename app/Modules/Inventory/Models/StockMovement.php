<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Database\Factories\StockMovementFactory;
use App\Modules\Inventory\Enums\StockMovementReason;
use App\Modules\Sellers\Models\Seller;
use App\Support\Database\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change to one shelf.
 *
 * Append-only, in both the trait and the database triggers: an oversell
 * dispute is settled by what this table says happened and in what order, so a
 * row that could be edited afterwards would settle nothing.
 *
 * Rows are written by App\Modules\Inventory\Services\StockLedger and by
 * nothing else, inside the same locked transaction that moved the quantity —
 * which is what makes `quantity_after` here and `quantity` on the variant two
 * facts that can be checked against each other rather than one fact stored
 * twice.
 *
 * @property int $id
 * @property int $product_variant_id
 * @property int $product_id
 * @property int $seller_id
 * @property int $quantity_change
 * @property int $quantity_before
 * @property int $quantity_after
 * @property StockMovementReason $reason
 * @property string|null $reference
 * @property int|null $order_id
 * @property string|null $note
 * @property int|null $actor_id
 * @property Carbon $created_at
 * @property-read ProductVariant $variant
 * @property-read Product $product
 * @property-read Seller $seller
 * @property-read User|null $actor
 */
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use AppendOnly, HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => StockMovementReason::class,
            'quantity_change' => 'integer',
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
            'created_at' => 'datetime',
        ];
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
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function isDecrement(): bool
    {
        return $this->quantity_change < 0;
    }

    /**
     * "Sold — 2 (3 left)": one line of a shelf's history.
     */
    public function summary(): string
    {
        return sprintf(
            '%s %s%d (%d left)',
            $this->reason->label(),
            $this->quantity_change > 0 ? '+' : '−',
            abs($this->quantity_change),
            $this->quantity_after,
        );
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForOrder(Builder $query, int $orderId): void
    {
        $query->where('order_id', $orderId);
    }
}
