<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Admin\Database\Seeders\ContentPageSeeder;
use App\Modules\Catalog\Database\Seeders\DevelopmentListingSeeder;
use App\Modules\Catalog\Database\Seeders\PartCategorySeeder;
use App\Modules\Catalog\Database\Seeders\VehicleReferenceSeeder;
use App\Modules\Finance\Database\Seeders\VatRateSeeder;
use App\Modules\Identity\Database\Seeders\DevelopmentAccountSeeder;
use App\Modules\Identity\Database\Seeders\ZambianLocationSeeder;
use App\Modules\Inventory\Database\Seeders\DevelopmentStockSeeder;
use App\Modules\Ledger\Database\Seeders\LedgerAccountSeeder;
use App\Modules\Ledger\Database\Seeders\MonetisationPolicySeeder;
use App\Modules\Mechanics\Database\Seeders\MechanicSpecialitySeeder;
use App\Modules\Sellers\Database\Seeders\DevelopmentSellerSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            SettingsSeeder::class,
            /* The chart of accounts is infrastructure: nothing can be posted without it. */
            LedgerAccountSeeder::class,
            /* Reads the monetisation settings above, so it has to follow them. */
            MonetisationPolicySeeder::class,
            /* Opens the dated VAT schedule from the flat setting seeded above. */
            VatRateSeeder::class,
            ZambianLocationSeeder::class,
            VehicleReferenceSeeder::class,
            PartCategorySeeder::class,
            /* The mechanic directory's filter is only as good as this list. */
            MechanicSpecialitySeeder::class,
            /*
             * Last of the reference seeders: the terms page reads the terms
             * settings SettingsSeeder wrote, and starts at the version number
             * past checkout acceptances already point at.
             */
            ContentPageSeeder::class,
        ]);

        /* Demo accounts exist for local development only; see the README. */
        if (app()->environment('local')) {
            $this->call([
                DevelopmentAccountSeeder::class,
                DevelopmentSellerSeeder::class,
                DevelopmentListingSeeder::class,
                /* Last: it spreads stock and freshness across whatever was listed. */
                DevelopmentStockSeeder::class,
            ]);
        }
    }
}
