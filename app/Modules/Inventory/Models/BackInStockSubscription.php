<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Database\Factories\BackInStockSubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A buyer waiting on a shelf to be refilled.
 *
 * Spent rather than deleted once it fires. Keeping the row is what makes
 * "exactly once per subscriber" a fact the database enforces instead of a
 * property of whichever job happened to run: a second restock finds a row
 * with `notified_at` set and skips it, and a buyer who genuinely wants to be
 * told again has to ask again.
 *
 * @property int $id
 * @property int $product_variant_id
 * @property int $product_id
 * @property int $user_id
 * @property Carbon|null $notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductVariant $variant
 * @property-read Product $product
 * @property-read User $user
 */
class BackInStockSubscription extends Model
{
    /** @use HasFactory<BackInStockSubscriptionFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->notified_at === null;
    }

    /**
     * Subscribers who have not yet been told.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('notified_at');
    }
}
