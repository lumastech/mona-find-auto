<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Database\Factories\ReconciliationRunFactory;
use App\Modules\Payments\Enums\ReconciliationStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One night's comparison of the gateway against the books.
 *
 * @property int $id
 * @property Carbon $for_date
 * @property ReconciliationStatus $status
 * @property int $collections_checked
 * @property int $settlements_checked
 * @property int $transactions_checked
 * @property int $exception_count
 * @property Money $gateway_total_ngwee
 * @property Money $ledger_total_ngwee
 * @property Money $variance_ngwee
 * @property string|null $failure_reason
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property-read Collection<int, ReconciliationException> $exceptions
 */
class ReconciliationRun extends Model
{
    /** @use HasFactory<ReconciliationRunFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'for_date' => 'date',
            'status' => ReconciliationStatus::class,
            'collections_checked' => 'integer',
            'settlements_checked' => 'integer',
            'transactions_checked' => 'integer',
            'exception_count' => 'integer',
            'gateway_total_ngwee' => MoneyCast::class,
            'ledger_total_ngwee' => MoneyCast::class,
            'variance_ngwee' => MoneyCast::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ReconciliationException, $this>
     */
    public function exceptions(): HasMany
    {
        return $this->hasMany(ReconciliationException::class);
    }

    /**
     * Named `wasClean` rather than `isClean` because Eloquent already owns
     * that name for dirty-attribute tracking, and a run is a past event
     * anyway.
     */
    public function wasClean(): bool
    {
        return $this->status === ReconciliationStatus::Clean;
    }
}
