<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Database\Factories\RefundFactory;
use App\Modules\Payments\Enums\RefundMethod;
use App\Modules\Payments\Enums\RefundReason;
use App\Modules\Payments\Enums\RefundStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money going back to a buyer.
 *
 * @property int $id
 * @property int $order_id
 * @property int $user_id
 * @property int|null $order_dispute_id
 * @property string $reference
 * @property RefundStatus $status
 * @property RefundMethod $method
 * @property RefundReason|null $reason_code
 * @property Money $amount_ngwee
 * @property string|null $destination_phone
 * @property string|null $destination_network
 * @property string|null $destination_account
 * @property string|null $destination_bank_code
 * @property string|null $lenco_transfer_id
 * @property string|null $failure_reason
 * @property string|null $notes
 * @property int|null $journal_entry_id
 * @property int|null $created_by
 * @property int|null $processed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property-read Order $order
 * @property-read User $buyer
 */
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'method' => RefundMethod::class,
            'reason_code' => RefundReason::class,
            'amount_ngwee' => MoneyCast::class,
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
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
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * The reference this refund's transfer carries at the gateway.
     *
     * Counts existing refunds on the order, so a partially refunded order
     * that is later refunded again gets RF-{order}-2 rather than colliding
     * with the first — Lenco refuses a reference it has seen.
     */
    public static function referenceFor(Order $order, int $sequence): string
    {
        return sprintf('RF-%s-%d', $order->number, $sequence);
    }

    /**
     * Where the money is going, in the shape the gateway wants.
     *
     * @return array<string, string>
     */
    public function recipientPayload(): array
    {
        return array_filter([
            'phone' => $this->destination_phone,
            'network' => $this->destination_network,
            'account_number' => $this->destination_account,
            'bank_code' => $this->destination_bank_code,
            'narration' => 'MonaFind refund '.$this->reference,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * Whether this refund can actually be sent.
     *
     * A mobile-money refund with no phone number on the original payment is
     * not sendable, and would otherwise fail at the gateway on every retry.
     * Those become manual too.
     */
    public function hasUsableDestination(): bool
    {
        return match ($this->method) {
            RefundMethod::MobileMoney => filled($this->destination_phone) && filled($this->destination_network),
            RefundMethod::Bank => filled($this->destination_account) && filled($this->destination_bank_code),
            default => false,
        };
    }

    /**
     * The Finance task queue.
     *
     * @param  Builder<Refund>  $query
     * @return Builder<Refund>
     */
    public function scopeNeedingAttention(Builder $query): Builder
    {
        return $query->whereIn('status', [RefundStatus::Manual->value, RefundStatus::Failed->value]);
    }
}
