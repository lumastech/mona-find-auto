<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Orders\Database\Factories\OrderFactory;
use App\Modules\Orders\Enums\DisputeStatus;
use App\Modules\Orders\Enums\FulfilmentMethod;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Support\DeliveryAddress;
use App\Modules\Orders\Support\MonetisationSnapshot;
use App\Modules\Orders\Support\OrderStockLine;
use App\Modules\Sellers\Enums\PaymentMode;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One seller's order.
 *
 * Read the snapshot columns as a group and the model makes sense: an order is
 * a contract, and a contract that reads its terms from live settings is not a
 * contract at all. payment_mode, monetisation_snapshot and the two window
 * lengths are copied on at payment time and never refreshed, so an
 * administrator raising the default commission on Thursday changes nothing
 * about Wednesday's sales.
 *
 * The model itself never changes its own status. OrderStateMachine is the
 * only writer, for the same reason StockLedger is the only writer of
 * quantities: the guards, the timeline row and the domain events have to
 * happen together or not at all.
 *
 * @property int $id
 * @property string $number
 * @property int $order_group_id
 * @property int $user_id
 * @property int $seller_id
 * @property OrderStatus $status
 * @property FulfilmentMethod $fulfilment_method
 * @property int|null $user_address_id
 * @property array<string, mixed>|null $delivery_address
 * @property string|null $delivery_instructions
 * @property Money $items_total_ngwee
 * @property Money $delivery_fee_ngwee
 * @property Money $total_ngwee
 * @property PaymentMode|null $payment_mode
 * @property array<string, mixed>|null $monetisation_snapshot
 * @property int|null $auto_complete_window_days
 * @property int|null $seller_confirm_window_hours
 * @property Carbon|null $snapshot_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $ready_at
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $handed_over_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $disputed_at
 * @property Carbon|null $refunded_at
 * @property Carbon|null $confirm_due_at
 * @property Carbon|null $auto_complete_at
 * @property string|null $cancellation_reason
 * @property Money $refunded_amount_ngwee
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OrderGroup $group
 * @property-read User $buyer
 * @property-read Seller $seller
 * @property-read Collection<int, OrderItem> $items
 * @property-read Collection<int, OrderStatusEvent> $statusEvents
 * @property-read Collection<int, OrderDispute> $disputes
 * @property-read TermsAcceptance|null $termsAcceptance
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'fulfilment_method' => FulfilmentMethod::class,
            'payment_mode' => PaymentMode::class,
            'delivery_address' => 'array',
            'monetisation_snapshot' => 'array',
            'items_total_ngwee' => MoneyCast::class,
            'delivery_fee_ngwee' => MoneyCast::class,
            'total_ngwee' => MoneyCast::class,
            'refunded_amount_ngwee' => MoneyCast::class,
            'auto_complete_window_days' => 'integer',
            'seller_confirm_window_hours' => 'integer',
            'snapshot_at' => 'datetime',
            'paid_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'ready_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'handed_over_at' => 'datetime',
            'completed_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'disputed_at' => 'datetime',
            'refunded_at' => 'datetime',
            'confirm_due_at' => 'datetime',
            'auto_complete_at' => 'datetime',
        ];
    }

    public static function booted(): void
    {
        static::creating(function (self $order): void {
            if (blank($order->number)) {
                $order->number = self::generateNumber();
            }
        });
    }

    /**
     * A short reference a buyer can read out over a counter.
     *
     * Drawn from a ULID's tail, so it is unpredictable and time-ordered
     * without being a count of how much business the platform has done. Eight
     * Crockford characters give enough room that the collision loop below
     * effectively never runs; it is there because "effectively never" is not
     * the same as never.
     */
    public static function generateNumber(): string
    {
        do {
            $number = 'MF-'.Str::upper(Str::substr((string) Str::ulid(), -8));
        } while (static::query()->where('number', $number)->exists());

        return $number;
    }

    /**
     * @return BelongsTo<OrderGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(OrderGroup::class, 'order_group_id');
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
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * The timeline, oldest first — the order it happened in.
     *
     * @return HasMany<OrderStatusEvent, $this>
     */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class)->orderBy('id');
    }

    /**
     * @return HasMany<OrderDispute, $this>
     */
    public function disputes(): HasMany
    {
        return $this->hasMany(OrderDispute::class)->orderByDesc('id');
    }

    /**
     * @return HasOne<TermsAcceptance, $this>
     */
    public function termsAcceptance(): HasOne
    {
        return $this->hasOne(TermsAcceptance::class);
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function belongsToBuyer(User $user): bool
    {
        return $this->user_id === $user->getKey();
    }

    /**
     * Whether this person runs the shop the order was placed with.
     */
    public function belongsToSellerOf(User $user): bool
    {
        return $user->seller()->whereKey($this->seller_id)->exists();
    }

    /**
     * The dispute currently holding this order up, if there is one.
     */
    public function openDispute(): ?OrderDispute
    {
        return $this->disputes()->open()->first();
    }

    /**
     * Whether anything is stopping this order completing on its own.
     *
     * A dispute blocks auto-completion, and it has to be read from the
     * disputes table rather than from the order's status: a moderator may
     * move an order back out of Disputed while still deciding, and the
     * sweep must not race in behind them.
     */
    public function hasOpenDispute(): bool
    {
        return $this->disputes()->open()->exists();
    }

    /**
     * The delivery address as it stood when the order was placed.
     */
    public function deliveryAddress(): ?DeliveryAddress
    {
        return $this->delivery_address === null
            ? null
            : DeliveryAddress::fromArray($this->delivery_address);
    }

    /**
     * The commercial terms this order runs on.
     *
     * Null until payment: an order nobody paid for never acquired any.
     */
    public function monetisation(): ?MonetisationSnapshot
    {
        return $this->monetisation_snapshot === null
            ? null
            : MonetisationSnapshot::fromArray($this->monetisation_snapshot);
    }

    /**
     * What MonaFind takes off this order, from the snapshot it carries.
     */
    public function commission(): Money
    {
        return $this->monetisation()?->commissionOn($this->items_total_ngwee) ?? Money::zero();
    }

    /**
     * What reaches the seller, before any rolling reserve.
     *
     * The delivery fee passes through untouched: MonaFind takes commission on
     * the goods, not on a courier's time.
     */
    public function sellerPayout(): Money
    {
        $snapshot = $this->monetisation();

        if ($snapshot === null) {
            return Money::zero();
        }

        return $snapshot->sellerPayout($this->items_total_ngwee)
            ->plus($this->delivery_fee_ngwee)
            ->minus($this->refunded_amount_ngwee);
    }

    /**
     * What was bought, reduced to what Inventory needs to hear.
     *
     * @return array<int, OrderStockLine>
     */
    public function stockLines(): array
    {
        return $this->items->map(
            static fn (OrderItem $item): OrderStockLine => new OrderStockLine(
                variantId: $item->product_variant_id,
                quantity: $item->quantity,
            ),
        )->all();
    }

    /**
     * The reference the money moved under, for the stock ledger and the
     * documents. Falls back to the order number before payment.
     */
    public function paymentReference(): string
    {
        $group = $this->relationLoaded('group') ? $this->group : $this->group()->first();

        if ($group === null) {
            return $this->number;
        }

        return sprintf('MFA-%s-%d', $group->public_id, max(1, $group->payment_attempts));
    }

    public function isPaid(): bool
    {
        return $this->status->isPaid() && $this->paid_at !== null;
    }

    /**
     * Whether the buyer may still confirm receipt themselves.
     */
    public function awaitsBuyerConfirmation(): bool
    {
        return $this->status->awaitsBuyerConfirmation() && ! $this->hasOpenDispute();
    }

    /**
     * Whether the buyer may raise a problem right now.
     */
    public function allowsDispute(): bool
    {
        return $this->status->allowsDispute() && ! $this->hasOpenDispute();
    }

    /**
     * A buyer's orders, newest first.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForBuyer(Builder $query, User $user): void
    {
        $query->where('user_id', $user->getKey())->latest('id');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForSeller(Builder $query, Seller $seller): void
    {
        $query->where('seller_id', $seller->getKey())->latest('id');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeWithStatus(Builder $query, ?OrderStatus $status): void
    {
        if ($status !== null) {
            $query->where('status', $status);
        }
    }

    /**
     * Paid orders whose seller has run out of time to answer.
     *
     * The deadline is a stored column rather than `paid_at + N hours` so the
     * sweep is an index scan, and so that a setting changed today cannot move
     * a deadline an order is already running against.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAwaitingSellerConfirmation(Builder $query, ?CarbonInterface $asOf = null): void
    {
        $query->where('status', OrderStatus::Paid)
            ->whereNotNull('confirm_due_at')
            ->where('confirm_due_at', '<=', $asOf ?? now());
    }

    /**
     * Handed-over orders whose checking window has closed.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeReadyToAutoComplete(Builder $query, ?CarbonInterface $asOf = null): void
    {
        $query->whereIn('status', [OrderStatus::Collected->value, OrderStatus::Delivered->value])
            ->whereNotNull('auto_complete_at')
            ->where('auto_complete_at', '<=', $asOf ?? now())
            /*
             * An open dispute takes the order out of the sweep entirely.
             * Read off the disputes table rather than off the order's own
             * status: a moderator may move an order back out of Disputed
             * while still deciding, and auto-completion must not slip in
             * behind them. The statuses come from DisputeStatus so this and
             * OrderDispute::scopeOpen() cannot drift apart.
             */
            ->whereDoesntHave('disputes', function (Builder $disputes): void {
                $disputes->whereIn('status', DisputeStatus::openValues());
            });
    }

    /**
     * Free-text search over what staff actually type into the box.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('number', 'like', "%{$term}%")
                ->orWhereHas('buyer', function (Builder $buyer) use ($term): void {
                    $buyer->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%");
                })
                ->orWhereHas('seller', function (Builder $seller) use ($term): void {
                    $seller->where('business_name', 'like', "%{$term}%");
                });
        });
    }
}
