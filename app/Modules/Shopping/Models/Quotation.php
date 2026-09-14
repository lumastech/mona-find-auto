<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Shopping\Database\Factories\QuotationFactory;
use App\Modules\Shopping\Enums\QuotationStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A request for quotation, and the seller's answer to it.
 *
 * Nothing here moves the status — App\Modules\Shopping\Services\QuotationService
 * owns the lifecycle, so every move is checked against
 * QuotationStatus::allowedTransitions(), recorded and announced.
 *
 * The expiry rule is the one subtlety. `valid_until` is a date, not a
 * timestamp, and a quote is good *through* that day: a seller writing "valid
 * until the 20th" means the buyer may accept on the 20th. hasExpired()
 * therefore compares against the end of that day, and the sweep that voids
 * stale quotes uses the same comparison — a quote must not be acceptable on
 * screen and expired in the database.
 *
 * @property int $id
 * @property int $user_id
 * @property int $seller_id
 * @property int $product_id
 * @property int $product_variant_id
 * @property QuotationStatus $status
 * @property int $quantity
 * @property string|null $message
 * @property Money|null $quoted_unit_price_ngwee
 * @property Carbon|null $valid_until
 * @property string|null $delivery_note
 * @property string|null $decline_reason
 * @property Carbon|null $quoted_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $declined_at
 * @property Carbon|null $expired_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $buyer
 * @property-read Seller $seller
 * @property-read Product $product
 * @property-read ProductVariant $variant
 */
class Quotation extends Model
{
    /** @use HasFactory<QuotationFactory> */
    use HasFactory;

    /** Nobody negotiates a hundred thousand brake pads on a parts marketplace. */
    public const MAX_QUANTITY = 10000;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'quantity' => 'integer',
            'quoted_unit_price_ngwee' => MoneyCast::class,
            'valid_until' => 'date',
            'quoted_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
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

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Whether the validity date has passed.
     *
     * A quote with no date on it has not expired — only a quoted price
     * carries one, and an open request is waiting on the seller rather than
     * on the clock.
     */
    public function hasExpired(?DateTimeInterface $asOf = null): bool
    {
        if ($this->valid_until === null) {
            return false;
        }

        return $this->valid_until->endOfDay()->isBefore($asOf ?? now());
    }

    /**
     * Whether the buyer may turn this into a cart line right now.
     *
     * Both halves are needed. A quote can be `quoted` in the database and
     * still be dead, because expiry is the passage of time rather than
     * something anybody did — the sweep only writes down what was already
     * true.
     */
    public function isAcceptable(): bool
    {
        return $this->status === QuotationStatus::Quoted && ! $this->hasExpired();
    }

    /**
     * What the buyer pays per unit if they accept: the quoted price, or the
     * shelf price where there is no quote.
     */
    public function effectiveUnitPrice(): Money
    {
        return $this->quoted_unit_price_ngwee ?? $this->variant->price;
    }

    /**
     * What the whole quoted quantity comes to.
     */
    public function total(): Money
    {
        return $this->effectiveUnitPrice()->times($this->quantity);
    }

    /**
     * Requests still going somewhere: asked but unanswered, or answered and
     * unspent.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeStillOpen(Builder $query): void
    {
        $query->whereIn('status', [QuotationStatus::Open, QuotationStatus::Quoted]);
    }

    /**
     * Quoted prices whose day has passed — what the expiry sweep collects.
     *
     * The comparison is against the date column rather than a computed
     * timestamp so the database can use the index: "valid_until is before
     * today" is exactly "the quote was good through a day that has ended".
     *
     * @param  Builder<$this>  $query
     */
    public function scopeStale(Builder $query, ?DateTimeInterface $asOf = null): void
    {
        $today = Carbon::instance($asOf ?? now())->startOfDay();

        $query->where('status', QuotationStatus::Quoted)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', $today);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForBuyer(Builder $query, User $buyer): void
    {
        $query->where('user_id', $buyer->getKey());
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForSeller(Builder $query, Seller $seller): void
    {
        $query->where('seller_id', $seller->getKey());
    }
}
