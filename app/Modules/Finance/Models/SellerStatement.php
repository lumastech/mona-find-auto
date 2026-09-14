<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Database\Factories\SellerStatementFactory;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One seller's month, as the ledger had it when the month closed.
 *
 * Nothing here is recomputed on read. A statement is a document, and a
 * document that changes when you open it again is not one — a seller who
 * printed October's figures in November must get the same page in March, even
 * if an adjustment has since been backdated into October.
 *
 * The closing figures are positions rather than movements: `closing_payable`
 * is what the seller was owed at the last instant of the month, including
 * money earned earlier and not yet paid. It is signed, so a clawback that
 * outran the seller's earnings shows as the debt it is.
 *
 * @property int $id
 * @property int $seller_id
 * @property int $period_year
 * @property int $period_month
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $order_count
 * @property Money $sales_ngwee
 * @property Money $commission_ngwee
 * @property Money $addon_fee_ngwee
 * @property Money $referral_fee_ngwee
 * @property Money $vat_on_commission_ngwee
 * @property Money $refunds_ngwee
 * @property Money $payouts_ngwee
 * @property Money $reserve_withheld_ngwee
 * @property Money $reserve_released_ngwee
 * @property Money $closing_payable_ngwee
 * @property Money $closing_reserve_ngwee
 * @property Carbon $generated_at
 * @property-read Seller $seller
 */
class SellerStatement extends Model
{
    /** @use HasFactory<SellerStatementFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'order_count' => 'integer',
            'sales_ngwee' => MoneyCast::class,
            'commission_ngwee' => MoneyCast::class,
            'addon_fee_ngwee' => MoneyCast::class,
            'referral_fee_ngwee' => MoneyCast::class,
            'vat_on_commission_ngwee' => MoneyCast::class,
            'refunds_ngwee' => MoneyCast::class,
            'payouts_ngwee' => MoneyCast::class,
            'reserve_withheld_ngwee' => MoneyCast::class,
            'reserve_released_ngwee' => MoneyCast::class,
            'closing_payable_ngwee' => MoneyCast::class,
            'closing_reserve_ngwee' => MoneyCast::class,
            'generated_at' => 'datetime',
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
     * Everything MonaFind charged this seller in the month.
     *
     * VAT is included because it is part of what was deducted from the
     * seller's money — it is simply owed onward to ZRA rather than kept.
     */
    public function totalDeductions(): Money
    {
        return $this->commission_ngwee
            ->plus($this->addon_fee_ngwee)
            ->plus($this->referral_fee_ngwee)
            ->plus($this->vat_on_commission_ngwee);
    }

    /**
     * What the month earned the seller before any payout.
     */
    public function netEarnings(): Money
    {
        return $this->sales_ngwee->minus($this->totalDeductions())->minus($this->refunds_ngwee);
    }

    /**
     * "2026-09", which is what a filename and a URL both want.
     */
    public function period(): string
    {
        return sprintf('%04d-%02d', $this->period_year, $this->period_month);
    }

    public function periodLabel(): string
    {
        return $this->period_start->format('F Y');
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForSeller(Builder $query, Seller $seller): void
    {
        $query->where('seller_id', $seller->getKey())
            ->orderByDesc('period_year')
            ->orderByDesc('period_month');
    }
}
