<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Orders\Database\Factories\OrderDisputeFactory;
use App\Modules\Orders\Enums\DisputeReason;
use App\Modules\Orders\Enums\DisputeResolution;
use App\Modules\Orders\Enums\DisputeStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A buyer's objection, and what MonaFind decided.
 *
 * An open dispute is the one thing that stops the platform completing an
 * order on a timer and, later, stops escrow releasing. That is the point of
 * it: without a hold, a buyer with a cracked housing would find the seller
 * paid out three days after the courier's handover and nothing left to
 * argue with.
 *
 * @property int $id
 * @property int $order_id
 * @property int $opened_by
 * @property DisputeReason $reason
 * @property string $details
 * @property DisputeStatus $status
 * @property DisputeResolution|null $resolution
 * @property Money $refund_amount_ngwee
 * @property string|null $resolution_note
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 * @property-read User $opener
 * @property-read User|null $resolver
 */
class OrderDispute extends Model implements HasMedia
{
    /** @use HasFactory<OrderDisputeFactory> */
    use HasFactory, InteractsWithMedia;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => DisputeReason::class,
            'status' => DisputeStatus::class,
            'resolution' => DisputeResolution::class,
            'refund_amount_ngwee' => MoneyCast::class,
            'resolved_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * The buyer's photographs.
     *
     * A dispute about a cracked housing is settled by looking at the housing,
     * so the evidence collection is part of the record rather than an
     * attachment to a message somewhere.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('evidence')
            ->useDisk('public')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Disputes still holding an order up.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', DisputeStatus::openValues());
    }

    /**
     * The moderator queue: what nobody has finished with, oldest first.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInQueue(Builder $query): void
    {
        $query->open()->oldest('created_at');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeWithStatus(Builder $query, ?DisputeStatus $status): void
    {
        if ($status !== null) {
            $query->where('status', $status);
        }
    }
}
