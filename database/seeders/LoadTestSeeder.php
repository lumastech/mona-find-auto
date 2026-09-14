<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Enums\PartSourcing;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Make;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\VehicleModel;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Models\City;
use App\Modules\Inventory\Enums\FreshnessState;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The corpus the load test runs against: 50 shops, 5,000 listings.
 *
 * The numbers come from the pilot target in the brief, and the shape matters
 * as much as the size. A load test against 5,000 identical listings measures
 * the query planner's luck rather than the platform: every card would hit the
 * same category, the same make, the same freshness state, and Meilisearch
 * would answer every query from one hot page of the index.
 *
 * So the spread here is deliberate:
 *
 * - Listings are distributed across every seeded category and make, so facet
 *   counts have to be computed rather than guessed.
 * - Condition, inspection and freshness are spread across their states, which
 *   is what makes the ranking weights do real work instead of tying on every
 *   row and falling through to price.
 * - Prices span three orders of magnitude, so "price descending" within a
 *   tier actually sorts something.
 * - A tenth of the shops are unverified and a twentieth of the listings are
 *   unpublished, because `Product::scopePublished()` is the filter every
 *   storefront query carries and a corpus where it excludes nothing would
 *   never exercise it.
 *
 * ## Inserted in bulk, on purpose
 *
 * Products go in through `DB::table()->insert()` in chunks rather than
 * through the factory. Five thousand models, each firing its observers and a
 * Scout index write, takes minutes and floods the queue; this takes seconds.
 * Nothing here needs the model events — the listings are fixtures, not
 * something a person did.
 *
 * Run it with `php artisan db:seed --class=LoadTestSeeder` against a database
 * you are willing to lose. It refuses to run in production.
 */
class LoadTestSeeder extends Seeder
{
    public const SELLERS = 50;

    public const LISTINGS = 5_000;

    private const CHUNK = 500;

    /**
     * Overridable so a test can prove the seeder works at a size that does
     * not take a minute. The defaults are the pilot targets from the brief
     * and are what the committed load-test figures were measured against.
     */
    public function __construct(
        private readonly int $sellerCount = self::SELLERS,
        private readonly int $listingCount = self::LISTINGS,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->say('LoadTestSeeder will not run in production.');

            return;
        }

        $cities = City::query()->get(['id', 'province_id'])->all();
        $categories = Category::query()->pluck('id')->all();
        $makes = Make::query()->pluck('id')->all();

        if ($cities === [] || $categories === [] || $makes === []) {
            $this->say('Seed the reference data first: php artisan db:seed.');

            return;
        }

        $sellers = $this->seedSellers($cities);

        $this->seedListings($sellers, $categories, $makes);

        $this->say(sprintf(
            'Load-test corpus ready: %d shops, %d listings.',
            count($sellers),
            Product::query()->count(),
        ));
    }

    /**
     * The console, when there is one.
     *
     * Laravel's own `$command` is an untyped property it leaves UNSET rather
     * than null when a seeder is constructed directly — which is how the test
     * for this seeder runs it, and why `$this->command->info()` would be a
     * fatal error there. Holding our own nullable reference says that in the
     * type system instead of probing for it.
     */
    private ?Command $console = null;

    public function setCommand(Command $command): static
    {
        $this->console = $command;

        return parent::setCommand($command);
    }

    /**
     * Write a line when there is a console to write to.
     */
    private function say(string $message): void
    {
        $this->console?->info($message);
    }

    /**
     * @param  array<int, City>  $cities
     * @return array<int, int> Seller ids.
     */
    private function seedSellers(array $cities): array
    {
        $existing = Seller::query()->where('business_name', 'like', 'Load Test %')->pluck('id')->all();

        if (count($existing) >= $this->sellerCount) {
            return $existing;
        }

        $ids = $existing;
        $types = SellerType::cases();

        for ($index = count($existing); $index < $this->sellerCount; $index++) {
            $city = $cities[$index % count($cities)];

            /*
             * Fillable columns through create(), the rest through forceFill.
             * `name`, `status` and the verification stamps are guarded on
             * User — `Model::preventSilentlyDiscardingAttributes()` turns
             * passing them to create() into an exception rather than letting
             * them vanish, which is how this seeder was wrong the first time.
             */
            $user = User::query()->create([
                'first_name' => 'Load',
                'last_name' => 'Tester '.$index,
                'email' => sprintf('load-seller-%d@monafind.test', $index),
                'phone' => sprintf('+26097%07d', 1_000_000 + $index),
                'password' => 'password',
                'province_id' => $city->province_id,
                'city_id' => $city->id,
                'street' => 'Load Test Road',
            ]);

            $user->forceFill([
                'status' => AccountStatus::Active,
                'status_changed_at' => now(),
                'phone_verified_at' => now(),
                'email_verified_at' => now(),
            ])->save();

            $user->assignRole(Role::Buyer->value, Role::Seller->value);

            /*
             * One shop in ten is unverified. `scopePublished()` filters on
             * seller visibility, and a corpus where that clause excludes
             * nothing would never measure it.
             */
            $verified = $index % 10 !== 0;

            $seller = Seller::query()->forceCreate([
                'user_id' => $user->id,
                'business_name' => 'Load Test Motors '.$index,
                'slug' => 'load-test-motors-'.$index,
                'type' => $types[$index % count($types)],
                'verification_status' => $verified ? VerificationStatus::Verified : VerificationStatus::Submitted,
                'verified_at' => $verified ? now()->subDays($index + 1) : null,
                'province_id' => $city->province_id,
                'city_id' => $city->id,
                'street' => 'Load Test Road',
                'phone' => sprintf('+26097%07d', 2_000_000 + $index),
                'email' => sprintf('shop-%d@monafind.test', $index),
                'contact_person' => 'Load Tester '.$index,
                'offers_pickup' => true,
                'offers_delivery' => $index % 3 !== 0,
            ]);

            $ids[] = $seller->id;
        }

        return $ids;
    }

    /**
     * @param  array<int, int>  $sellers
     * @param  array<int, int>  $categories
     * @param  array<int, int>  $makes
     */
    private function seedListings(array $sellers, array $categories, array $makes): void
    {
        $already = Product::query()->where('slug', 'like', 'load-test-%')->count();

        if ($already >= $this->listingCount) {
            return;
        }

        $modelsByMake = VehicleModel::query()
            ->get(['id', 'make_id'])
            ->groupBy('make_id')
            ->map(static fn ($models): array => $models->pluck('id')->all())
            ->all();

        $conditions = Condition::cases();
        $inspections = InspectionStatus::cases();
        $sourcings = PartSourcing::cases();
        $freshness = FreshnessState::cases();
        $parts = $this->partNames();

        $now = now();

        for ($offset = $already; $offset < $this->listingCount; $offset += self::CHUNK) {
            $rows = [];
            $limit = min(self::CHUNK, $this->listingCount - $offset);

            for ($i = 0; $i < $limit; $i++) {
                $index = $offset + $i;
                $makeId = $makes[$index % count($makes)];
                $state = $freshness[$index % count($freshness)];

                $rows[] = [
                    'seller_id' => $sellers[$index % count($sellers)],
                    'category_id' => $categories[$index % count($categories)],
                    'make_id' => $makeId,
                    'vehicle_model_id' => $this->modelFor($modelsByMake, $makeId, $index),
                    'name' => $parts[$index % count($parts)].' — unit '.$index,
                    'slug' => 'load-test-'.$index.'-'.Str::random(6),
                    'description' => 'Load-test fixture listing number '.$index.'.',
                    'condition' => $conditions[$index % count($conditions)]->value,
                    'inspection_status' => $inspections[$index % count($inspections)]->value,
                    /* A filterable facet, so it alternates rather than being constant. */
                    'sourcing' => $sourcings[$index % count($sourcings)]->value,
                    /*
                     * One listing in twenty is not published, so the scope
                     * every storefront query carries has something to exclude.
                     */
                    'status' => $index % 20 === 0
                        ? ListingStatus::Unpublished->value
                        : ListingStatus::Published->value,
                    'published_at' => $index % 20 === 0 ? null : $now,
                    'year_from' => 2000 + ($index % 20),
                    'year_to' => 2005 + ($index % 18),
                    'delivery_available' => $index % 3 !== 0,
                    'freshness_state' => $state->value,
                    'freshness_confirmed_at' => $now->copy()->subDays($index % 8),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('products')->insert($rows);

        }

        $this->seedVariants();
    }

    /**
     * One variant per listing. Without a variant a listing has no price and
     * no stock, so every card would render "Out of stock" and the ranking
     * weights would never separate anything.
     */
    private function seedVariants(): void
    {
        Product::query()
            ->where('slug', 'like', 'load-test-%')
            ->whereDoesntHave('variants')
            ->select(['id'])
            ->chunkById(self::CHUNK, function ($products): void {
                $rows = [];
                $now = now();

                foreach ($products as $product) {
                    $id = (int) $product->id;

                    $rows[] = [
                        'product_id' => $id,
                        /* Single-variant listing: the name column stays null. */
                        'name' => null,
                        'sku' => 'LT-'.$id,
                        /*
                         * Price lives on the variant, not the listing. Three
                         * orders of magnitude spread across the corpus, so
                         * "price descending" within a ranking tier actually
                         * sorts something.
                         */
                        'price' => 5_000 + (($id * 7_919) % 2_000_000),
                        /* A tenth are out of stock: another clause worth exercising. */
                        'quantity' => $id % 10 === 0 ? 0 : 5 + ($id % 20),
                        'is_default' => true,
                        'position' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('product_variants')->insert($rows);
            });
    }

    /**
     * @param  array<int, array<int, int>>  $modelsByMake
     */
    private function modelFor(array $modelsByMake, int $makeId, int $index): ?int
    {
        $models = $modelsByMake[$makeId] ?? [];

        return $models === [] ? null : $models[$index % count($models)];
    }

    /**
     * @return array<int, string>
     */
    private function partNames(): array
    {
        return [
            'Brake pads', 'Brake discs', 'Clutch kit', 'Alternator', 'Starter motor',
            'Radiator', 'Water pump', 'Fuel pump', 'Shock absorber', 'Control arm',
            'Wheel bearing', 'Timing belt kit', 'Air filter', 'Oil filter', 'Cabin filter',
            'Headlamp', 'Tail lamp', 'Wing mirror', 'Windscreen', 'Bonnet',
            'Cylinder head', 'Turbocharger', 'Injector set', 'Gearbox', 'Propshaft',
        ];
    }
}
