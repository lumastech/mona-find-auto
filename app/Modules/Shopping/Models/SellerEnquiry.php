<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Shopping\Database\Factories\SellerEnquiryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A message a buyer sent a seller from a listing.
 *
 * The stub of a thread. Full messaging — replies, attachments, moderation —
 * belongs to the Messaging module; what this row guarantees today is that
 * pressing "Contact seller" actually reaches somebody and leaves a record
 * both sides can point at.
 *
 * Written through App\Modules\Shopping\Contracts\SellerEnquiryChannel rather
 * than directly, so the storefront does not have to change when Messaging
 * takes the job over.
 *
 * @property int $id
 * @property int $user_id
 * @property int $seller_id
 * @property int|null $product_id
 * @property string $message
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $buyer
 * @property-read Seller $seller
 * @property-read Product|null $product
 */
class SellerEnquiry extends Model
{
    /** @use HasFactory<SellerEnquiryFactory> */
    use HasFactory;

    /** Long enough to describe a part and a car; short enough to read. */
    public const MAX_LENGTH = 2000;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }
}
