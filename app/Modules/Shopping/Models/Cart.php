<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Models;

use App\Models\User;
use App\Modules\Shopping\Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A buyer's cart. One per account, and it persists.
 *
 * Nothing here decides what a line costs or whether it can still be bought —
 * that is CartService, which re-reads the listings every time the cart is
 * shown. This model is the container and the relationships.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, CartItem> $items
 */
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }

    /**
     * How many individual parts are in the cart — the number on the header
     * badge.
     *
     * Units rather than lines: a buyer with one line of six gaskets has six
     * things in their cart, and a badge reading "1" would be telling them
     * something they would have to open the cart to correct.
     */
    public function unitCount(): int
    {
        return (int) $this->items()->sum('quantity');
    }

    public function isEmpty(): bool
    {
        return $this->items()->doesntExist();
    }
}
