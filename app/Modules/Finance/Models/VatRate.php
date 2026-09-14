<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Modules\Finance\Database\Factories\VatRateFactory;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One rate of VAT on commission, and the day it started applying.
 *
 * The table is a schedule read forwards: the rate in force at a moment is the
 * latest row dated on or before it. That makes a future rate something an
 * administrator can enter the day the statutory instrument is published
 * rather than something somebody has to remember to type at midnight.
 *
 * Nothing here is ever read by an order that has already been paid. The rate
 * is copied into `orders.monetisation_snapshot` at payment time, and every
 * invoice, statement and refund figure is derived from that copy. This model
 * decides what FUTURE orders are charged and nothing else — which is exactly
 * what makes changing it safe.
 *
 * @property int $id
 * @property string $rate_percent
 * @property Carbon $effective_from
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $author
 */
class VatRate extends Model
{
    /** @use HasFactory<VatRateFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The rate in force at a moment, or null if the schedule starts later.
     *
     * `whereDate` rather than a plain `where`, and that is not a stylistic
     * choice. Laravel writes a `date`-cast attribute with the model's full
     * datetime format, so the column holds "2026-06-01 00:00:00"; compared as
     * a string against "2026-06-01" that sorts AFTER the bound, and the rate
     * that commenced that morning would not be found until the following day.
     * Wrapping the column in DATE() compares the two as dates, which is what
     * a commencement date means.
     *
     * Ordered by date and then by id so two rows that somehow share a day
     * still resolve deterministically; the unique index means they cannot, but
     * a query that leans on an index for its ordering is one refactor away
     * from being wrong.
     */
    public static function inForceAt(CarbonInterface $moment): ?self
    {
        return self::query()
            ->whereDate('effective_from', '<=', $moment->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Rates that have not started yet — the ones still safe to withdraw.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeScheduled(Builder $query): void
    {
        $query->whereDate('effective_from', '>', now()->toDateString());
    }

    /**
     * Whether this row may still be deleted.
     *
     * A rate whose day has come may have been charged on a real order, and
     * the order is carrying it in its snapshot. Deleting the row would not
     * change that order by a ngwee, but it would destroy the platform's own
     * record of why it charged what it did.
     */
    public function isWithdrawable(): bool
    {
        return $this->effective_from->isAfter(now()->startOfDay());
    }
}
