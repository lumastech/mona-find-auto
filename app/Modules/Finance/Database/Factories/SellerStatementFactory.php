<?php

declare(strict_types=1);

namespace App\Modules\Finance\Database\Factories;

use App\Modules\Finance\Models\SellerStatement;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SellerStatement>
 */
class SellerStatementFactory extends Factory
{
    protected $model = SellerStatement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->subMonth()->startOfMonth();

        return [
            'seller_id' => Seller::factory(),
            'period_year' => (int) $start->format('Y'),
            'period_month' => (int) $start->format('n'),
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'order_count' => 4,
            'sales_ngwee' => 400_000,
            'commission_ngwee' => 30_000,
            'addon_fee_ngwee' => 0,
            'referral_fee_ngwee' => 0,
            'vat_on_commission_ngwee' => 4_800,
            'refunds_ngwee' => 0,
            'payouts_ngwee' => 0,
            'reserve_withheld_ngwee' => 0,
            'reserve_released_ngwee' => 0,
            'closing_payable_ngwee' => 365_200,
            'closing_reserve_ngwee' => 0,
            'generated_at' => now(),
        ];
    }
}
