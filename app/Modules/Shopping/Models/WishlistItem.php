<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Shopping\Database\Factories\WishlistItemFactory;
use App\Modules\Shopping\Support\WishlistChange;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A listing a buyer saved for later.
 *
 * The two `*_at_save` columns are what make this more than a bookmark. They
 * are written when the item is saved and never touched again, because the
 * whole value of the row is being able to say what has changed *since* — a
 * baseline that moved with the listing would report that nothing ever
 * happens.
 *
 * Reading that comparison is WishlistChange's job, not this model's: the
 * columns hold what was true, and the value object works out what it means.
 *
 * @property int $id
 * @property int $user_id
 * @property int $product_id
 * @property Money|null $price_ngwee_at_save
 * @property bool $in_stock_at_save
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Product $product
 */
class WishlistItem extends Model
{
    /** @use HasFactory<WishlistItemFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_ngwee_at_save' => MoneyCast::class,
            'in_stock_at_save' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * What has happened to this listing since the buyer saved it.
     *
     * Needs the listing's variants loaded — the current price and the current
     * stock position both come off them.
     */
    public function change(): WishlistChange
    {
        return WishlistChange::between($this, $this->product);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForUser(Builder $query, User $user): void
    {
        $query->where('user_id', $user->getKey());
    }
}
