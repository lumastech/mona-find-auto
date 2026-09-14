<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Database\Factories;

use App\Modules\Ledger\Models\CommissionInvoice;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\MonetisationSnapshot;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use App\Support\Money\RoundingMode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionInvoice>
 */
class CommissionInvoiceFactory extends Factory
{
    protected $model = CommissionInvoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = (int) now()->format('Y');
        $sequence = $this->faker->unique()->numberBetween(1, 99_999);

        $goods = Money::ofNgwee($this->faker->numberBetween(10_000, 500_000));
        $commission = $goods->percentage('7.50', RoundingMode::HalfUp);
        $vat = $commission->percentage('16.00', RoundingMode::HalfUp);

        return [
            'number' => sprintf('MFA-INV-%d-%05d', $year, $sequence),
            'series_year' => $year,
            'series_number' => $sequence,
            'seller_id' => Seller::factory(),
            'order_id' => Order::factory(),
            'goods_ngwee' => $goods->ngwee,
            'commission_ngwee' => $commission->ngwee,
            'addon_fee_ngwee' => 0,
            'referral_fee_ngwee' => 0,
            'vat_ngwee' => $vat->ngwee,
            'total_ngwee' => $commission->plus($vat)->ngwee,
            'vat_rate_percent' => '16.00',
            'monetisation_snapshot' => MonetisationSnapshot::fromSettings()->toArray(),
            'issued_at' => now(),
        ];
    }
}
