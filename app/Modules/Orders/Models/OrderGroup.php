<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Orders\Database\Factories\OrderGroupFactory;
use App\Modules\Orders\Enums\OrderGroupStatus;
use App\Modules\Orders\Enums\PaymentMethod;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One payment covering every shop in a cart.
 *
 * The group is short-lived in the sense that matters: it is the thing the
 * buyer pays, and once the money has landed it stops being interesting.
 * Everything afterwards happens to the orders inside it, each on its own
 * seller's timetable.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property OrderGroupStatus $status
 * @property PaymentMethod $payment_method
 * @property Money $items_total_ngwee
 * @property Money $delivery_total_ngwee
 * @property Money $total_ngwee
 * @property int $payment_attempts
 * @property Carbon|null $placed_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $buyer
 * @property-read Collection<int, Order> $orders
 */
class OrderGroup extends Model
{
    /** @use HasFactory<OrderGroupFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderGroupStatus::class,
            'payment_method' => PaymentMethod::class,
            'items_total_ngwee' => MoneyCast::class,
            'delivery_total_ngwee' => MoneyCast::class,
            'total_ngwee' => MoneyCast::class,
            'payment_attempts' => 'integer',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Give every group its public id before it is written.
     *
     * It has to exist at insert time rather than be derived afterwards
     * because the payment reference is built from it, and a payment cannot be
     * started against a row that does not yet know what it is called.
     */
    public static function booted(): void
    {
        static::creating(function (self $group): void {
            if (blank($group->public_id)) {
                $group->public_id = (string) Str::ulid();
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function isPaid(): bool
    {
        return $this->status->isPaid();
    }

    /**
     * The reference the next collection attempt should carry.
     *
     * The attempt number is part of it because a buyer whose card is declined
     * tries again, and Lenco will not accept the same reference twice. The
     * counter is incremented by Payments when it starts an attempt, so this
     * describes the attempt about to be made rather than the last one.
     */
    public function nextPaymentAttempt(): int
    {
        return $this->payment_attempts + 1;
    }

    /**
     * How many separate shops this payment covers — what the checkout page
     * uses to explain why one payment produces several deliveries.
     */
    public function sellerCount(): int
    {
        return $this->orders()->count();
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeAwaitingPayment(Builder $query): void
    {
        $query->where('status', OrderGroupStatus::PendingPayment);
    }
}
