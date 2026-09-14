<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Database\Factories;

use App\Models\User;
use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\DriveType;
use App\Modules\Catalog\Enums\FuelType;
use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Enums\PartSourcing;
use App\Modules\Catalog\Enums\Transmission;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Make;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\VehicleModel;
use App\Modules\Inventory\Enums\FreshnessState;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Listings come out published with one variant, because that is what nearly
 * every test of anything downstream needs. The lifecycle states below cover
 * the moderation workflow itself.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word().' '.fake()->word()).' Assembly';
        $yearFrom = fake()->numberBetween(1998, 2018);

        return [
            'seller_id' => Seller::factory(),
            'category_id' => Category::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'description' => fake()->paragraph(),
            'make_id' => Make::factory(),
            'vehicle_model_id' => null,
            'year_from' => $yearFrom,
            'year_to' => $yearFrom + fake()->numberBetween(0, 6),
            'condition' => Condition::Used,
            'inspection_status' => InspectionStatus::Uninspected,
            'sourcing' => fake()->randomElement(PartSourcing::cases()),
            'part_number' => strtoupper(fake()->bothify('??####-#####')),
            'oem_number' => null,
            'engine_size_cc' => fake()->randomElement([1300, 1500, 1800, 2000, 2500, 3000]),
            'engine_code' => strtoupper(fake()->bothify('##?-??')),
            'fuel_type' => fake()->randomElement(FuelType::cases()),
            'transmission' => fake()->randomElement(Transmission::cases()),
            'drive_type' => fake()->randomElement(DriveType::cases()),
            'body_type' => null,
            'trim' => null,
            'chassis_compatibility' => null,
            'warranty_text' => fake()->boolean(50) ? '30-day warranty on fitment.' : null,
            'delivery_available' => fake()->boolean(),
            'status' => ListingStatus::Published,
            'submitted_at' => now()->subDays(3),
            'published_at' => now()->subDays(2),
            /* Confirmed today, so a listing is visible unless a test says otherwise. */
            'freshness_confirmed_at' => now(),
            'freshness_state' => FreshnessState::Fresh,
        ];
    }

    /**
     * A listing without a variant has no price, and nothing downstream can
     * use it — so every product gets one unless a test says otherwise.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Product $product): void {
            if ($product->variants()->exists()) {
                return;
            }

            ProductVariantFactory::new()->for($product)->default()->create();
        });
    }

    public function ofSeller(Seller $seller): static
    {
        return $this->state([
            'seller_id' => $seller->getKey(),
            /* A breaker's stock is second-hand by definition, whatever the payload said. */
            'condition' => Condition::forcedFor($seller->type) ?? Condition::Used,
        ]);
    }

    public function inCategory(Category $category): static
    {
        return $this->state(['category_id' => $category->getKey()]);
    }

    public function fitting(Make $make, ?VehicleModel $model = null): static
    {
        return $this->state([
            'make_id' => $make->getKey(),
            'vehicle_model_id' => $model?->getKey(),
        ]);
    }

    public function condition(Condition $condition): static
    {
        return $this->state(['condition' => $condition]);
    }

    public function draft(): static
    {
        return $this->state([
            'status' => ListingStatus::Draft,
            'submitted_at' => null,
            'published_at' => null,
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state([
            'status' => ListingStatus::PendingReview,
            'submitted_at' => now(),
            'published_at' => null,
        ]);
    }

    public function rejected(string $reason = 'The photos do not show the part clearly.'): static
    {
        return $this->state([
            'status' => ListingStatus::Rejected,
            'rejection_reason' => $reason,
            'rejection_fields' => ['photos' => $reason],
            'published_at' => null,
        ]);
    }

    public function unpublished(): static
    {
        return $this->state([
            'status' => ListingStatus::Unpublished,
            'unpublished_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(['status' => ListingStatus::Archived, 'archived_at' => now()]);
    }

    /**
     * A listing whose stock was last confirmed a given number of days ago,
     * with the freshness state that implies.
     *
     * Pairs the timestamp with the state deliberately: setting one without
     * the other produces a listing the daily sweep would immediately
     * contradict, which is not a state any test wants to assert against.
     */
    public function stockConfirmedDaysAgo(int $days): static
    {
        return $this->state([
            'freshness_confirmed_at' => now()->subDays($days)->startOfDay(),
            'freshness_state' => FreshnessState::forAge($days, [
                'fresh' => 3,
                'ageing' => 5,
                'hidden' => 14,
            ]),
        ]);
    }

    /**
     * Off the storefront for going unconfirmed, without waiting a fortnight
     * for it.
     */
    public function stockHidden(): static
    {
        return $this->state([
            'freshness_confirmed_at' => now()->subDays(20)->startOfDay(),
            'freshness_state' => FreshnessState::Hidden,
            'freshness_hidden_at' => now()->subDays(6),
        ]);
    }

    /**
     * Badged by MonaFind staff. Independent of the condition, always.
     */
    public function inspected(?User $inspector = null): static
    {
        return $this->state([
            'inspection_status' => InspectionStatus::Inspected,
            'inspected_at' => now(),
            'inspected_by' => $inspector?->getKey(),
        ]);
    }
}
