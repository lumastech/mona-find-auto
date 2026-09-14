<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Modules\Ledger\Database\Factories\CommissionInvoiceFactory;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\MonetisationSnapshot;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * MonaFind's invoice to a seller for its commission, and only for that.
 *
 * The distinction the brief insists on: product prices are VAT-inclusive and
 * the seller answers for their own output tax on the goods. MonaFind invoices
 * its commission plus VAT on that commission. It never invoices the order.
 *
 * Every figure is copied from the order's snapshot at the moment revenue was
 * recognised, so an invoice reprinted three years later is the same document
 * — it recomputes nothing, and there is nothing live left for it to read.
 *
 * The PDF arrives in Prompt 14; this is the record it will render.
 *
 * @property int $id
 * @property string $number
 * @property int $series_year
 * @property int $series_number
 * @property int $seller_id
 * @property int $order_id
 * @property int|null $journal_entry_id
 * @property Money $goods_ngwee
 * @property Money $commission_ngwee
 * @property Money $addon_fee_ngwee
 * @property Money $referral_fee_ngwee
 * @property Money $vat_ngwee
 * @property Money $total_ngwee
 * @property string $vat_rate_percent
 * @property array<string, mixed> $monetisation_snapshot
 * @property Carbon $issued_at
 * @property-read Seller $seller
 * @property-read Order $order
 * @property-read JournalEntry|null $entry
 */
class CommissionInvoice extends Model
{
    /** @use HasFactory<CommissionInvoiceFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'goods_ngwee' => MoneyCast::class,
            'commission_ngwee' => MoneyCast::class,
            'addon_fee_ngwee' => MoneyCast::class,
            'referral_fee_ngwee' => MoneyCast::class,
            'vat_ngwee' => MoneyCast::class,
            'total_ngwee' => MoneyCast::class,
            'monetisation_snapshot' => 'array',
            'series_year' => 'integer',
            'series_number' => 'integer',
            'issued_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    /**
     * The terms this invoice was raised under.
     */
    public function monetisation(): MonetisationSnapshot
    {
        return MonetisationSnapshot::fromArray($this->monetisation_snapshot);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForSeller(Builder $query, Seller $seller): void
    {
        $query->where('seller_id', $seller->getKey())->latest('issued_at');
    }
}
