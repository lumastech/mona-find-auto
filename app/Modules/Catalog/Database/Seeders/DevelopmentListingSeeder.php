<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Database\Seeders;

use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\FuelType;
use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Enums\PartSourcing;
use App\Modules\Catalog\Enums\Transmission;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Make;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\VehicleModel;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use Illuminate\Database\Seeder;

/**
 * Listings for local development.
 *
 * One of each interesting state, spread across the demo shops, so the
 * moderation queue, the seller portal and the storefront all have something
 * real to render — including the pair that matters most: a car breaker whose
 * stock is badged Car Breaker automatically, and an inspected listing beside
 * an uninspected one.
 *
 * This only ever runs outside production; DatabaseSeeder guards the call.
 */
class DevelopmentListingSeeder extends Seeder
{
    public function __construct(private readonly ProductService $products) {}

    public function run(): void
    {
        $sellers = Seller::query()
            ->whereIn('verification_status', [VerificationStatus::Verified, VerificationStatus::Submitted])
            ->get()
            ->all();

        if ($sellers === []) {
            return;
        }

        foreach ($this->listings() as $index => $listing) {
            $seller = $sellers[$index % count($sellers)];
            $category = Category::query()->where('slug', $listing['category'])->first();
            $make = Make::query()->where('slug', $listing['make'])->first();

            if ($category === null || $make === null) {
                continue;
            }

            if (Product::query()->where('seller_id', $seller->id)->where('name', $listing['name'])->exists()) {
                continue;
            }

            $product = $this->products->create($seller, [
                'name' => $listing['name'],
                'description' => $listing['description'],
                'category_id' => $category->id,
                'make_id' => $make->id,
                'vehicle_model_id' => VehicleModel::query()
                    ->where('make_id', $make->id)
                    ->where('slug', $listing['model'])
                    ->value('id'),
                'year_from' => $listing['years'][0],
                'year_to' => $listing['years'][1],
                /*
                 * A breaker's stock is Car Breaker whatever the demo data
                 * says, and asserting anything else is refused rather than
                 * corrected — so ask the enum which condition applies rather
                 * than handing the service one it will reject.
                 */
                'condition' => Condition::forcedFor($seller->type) ?? $listing['condition'],
                'sourcing' => $listing['sourcing'],
                'part_number' => $listing['part_number'],
                'fuel_type' => $listing['fuel_type'],
                'transmission' => $listing['transmission'],
                'warranty_text' => $listing['warranty'],
                'delivery_available' => $listing['delivery'],
                'price' => Money::ofKwacha($listing['price']),
                'quantity' => $listing['quantity'],
            ]);

            $product->forceFill([
                'status' => $listing['status'],
                'submitted_at' => $listing['status'] === ListingStatus::Draft ? null : now()->subDays(4),
                'published_at' => $listing['status'] === ListingStatus::Published ? now()->subDays(3) : null,
                'inspection_status' => $listing['inspected']
                    ? InspectionStatus::Inspected
                    : InspectionStatus::Uninspected,
                'inspected_at' => $listing['inspected'] ? now()->subDays(2) : null,
            ])->save();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listings(): array
    {
        return [
            [
                'name' => 'Toyota Hilux 2KD fuel injector set',
                'description' => "Four Denso injectors off a 2KD-FTV. Tested on a bench and flow-matched.\n\nFitting is not included. Come with your engine number so we can confirm the code before you pay.",
                'category' => 'fuel-injectors',
                'make' => 'toyota',
                'model' => 'hilux',
                'years' => [2005, 2015],
                'condition' => Condition::Used,
                'sourcing' => PartSourcing::Oem,
                'part_number' => '23670-0L050',
                'fuel_type' => FuelType::Diesel,
                'transmission' => null,
                'warranty' => '14-day warranty against injector failure.',
                'delivery' => true,
                'price' => '4500',
                'quantity' => 3,
                'status' => ListingStatus::Published,
                'inspected' => true,
            ],
            [
                'name' => 'Nissan Hardbody front shock absorbers (pair)',
                'description' => "Brand new gas shocks for the Hardbody, sold as a pair.\n\nWe stock the bushes and top mounts separately if yours are gone.",
                'category' => 'shock-absorbers',
                'make' => 'nissan',
                'model' => 'hardbody',
                'years' => [1997, 2008],
                'condition' => Condition::BrandNew,
                'sourcing' => PartSourcing::Aftermarket,
                'part_number' => 'KYB-344459',
                'fuel_type' => null,
                'transmission' => null,
                'warranty' => '6 months against manufacturing defect.',
                'delivery' => true,
                'price' => '1850',
                'quantity' => 12,
                'status' => ListingStatus::Published,
                'inspected' => false,
            ],
            [
                'name' => 'Mitsubishi Pajero 4M40 complete cylinder head',
                'description' => "Head off a running Pajero, pressure tested and skimmed.\n\nValves and springs included. No injectors.",
                'category' => 'cylinder-heads',
                'make' => 'mitsubishi',
                'model' => 'pajero',
                'years' => [1994, 2006],
                'condition' => Condition::Used,
                'sourcing' => PartSourcing::Oem,
                'part_number' => 'ME202620',
                'fuel_type' => FuelType::Diesel,
                'transmission' => null,
                'warranty' => null,
                'delivery' => false,
                'price' => '7800',
                'quantity' => 1,
                'status' => ListingStatus::Published,
                'inspected' => false,
            ],
            [
                'name' => 'Toyota Corolla NZE alternator',
                'description' => 'Tested alternator for the 1NZ-FE Corolla. Charging checked at 14.2 volts.',
                'category' => 'alternators',
                'make' => 'toyota',
                'model' => 'corolla',
                'years' => [2001, 2008],
                'condition' => Condition::Used,
                'sourcing' => PartSourcing::Oem,
                'part_number' => '27060-21030',
                'fuel_type' => FuelType::Petrol,
                'transmission' => Transmission::Automatic,
                'warranty' => '30-day warranty.',
                'delivery' => true,
                'price' => '1650',
                'quantity' => 4,
                'status' => ListingStatus::PendingReview,
                'inspected' => false,
            ],
            [
                'name' => 'Ford Ranger T6 headlamp, left',
                'description' => 'Left-hand headlamp for the T6 Ranger. Lens is clear with no crazing.',
                'category' => 'headlamps',
                'make' => 'ford',
                'model' => 'ranger',
                'years' => [2012, 2019],
                'condition' => Condition::Used,
                'sourcing' => PartSourcing::Oem,
                'part_number' => 'AB39-13W030',
                'fuel_type' => null,
                'transmission' => null,
                'warranty' => null,
                'delivery' => false,
                'price' => '2400',
                'quantity' => 2,
                'status' => ListingStatus::Draft,
                'inspected' => false,
            ],
        ];
    }
}
