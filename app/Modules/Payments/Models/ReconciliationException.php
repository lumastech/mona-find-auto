<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Modules\Orders\Models\OrderGroup;
use App\Modules\Payments\Database\Factories\ReconciliationExceptionFactory;
use App\Modules\Payments\Enums\ReconciliationExceptionType;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing that did not add up.
 *
 * Resolved by annotation, never by deletion: an exception that can be made to
 * disappear is an exception nobody has to explain.
 *
 * @property int $id
 * @property int $reconciliation_run_id
 * @property ReconciliationExceptionType $type
 * @property string $severity
 * @property string|null $reference
 * @property string|null $lenco_id
 * @property int|null $payment_id
 * @property int|null $order_group_id
 * @property Money|null $gateway_amount_ngwee
 * @property Money|null $ledger_amount_ngwee
 * @property Money|null $variance_ngwee
 * @property string $detail
 * @property array<string, mixed>|null $context
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property string|null $resolution_note
 */
class ReconciliationException extends Model
{
    /** @use HasFactory<ReconciliationExceptionFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ReconciliationExceptionType::class,
            'gateway_amount_ngwee' => MoneyCast::class,
            'ledger_amount_ngwee' => MoneyCast::class,
            'variance_ngwee' => MoneyCast::class,
            'context' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReconciliationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class, 'reconciliation_run_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<OrderGroup, $this>
     */
    public function orderGroup(): BelongsTo
    {
        return $this->belongsTo(OrderGroup::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * @param  Builder<ReconciliationException>  $query
     * @return Builder<ReconciliationException>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
