<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Database\Factories;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Shopping\Models\SellerEnquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SellerEnquiry>
 */
class SellerEnquiryFactory extends Factory
{
    protected $model = SellerEnquiry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'seller_id' => Seller::factory(),
            'product_id' => null,
            'message' => 'Do you have this for a 2012 Hilux, and can you deliver to Kabwe?',
            'read_at' => null,
        ];
    }

    public function aboutProduct(Product $product): static
    {
        return $this->state([
            'product_id' => $product->getKey(),
            'seller_id' => $product->seller_id,
        ]);
    }

    public function read(): static
    {
        return $this->state(['read_at' => now()]);
    }
}
