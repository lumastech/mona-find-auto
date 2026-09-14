<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Database\Seeders;

use App\Models\User;
use App\Modules\Identity\Models\City;
use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Services\PayoutAccountService;
use App\Modules\Sellers\Services\SellerPolicyService;
use App\Support\Roles\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A handful of shops for local development.
 *
 * One of each interesting state — verified, waiting in the queue, rejected —
 * so the admin verification queue and the storefront both have something real
 * to render. This only ever runs outside production; DatabaseSeeder guards
 * the call.
 */
class DevelopmentSellerSeeder extends Seeder
{
    public function __construct(
        private readonly SellerPolicyService $policies,
        private readonly PayoutAccountService $payouts,
    ) {}

    public function run(): void
    {
        $lusaka = City::query()->where('slug', 'lusaka')->first();

        if ($lusaka === null) {
            return;
        }

        foreach ($this->businesses() as $index => $business) {
            $owner = $this->ownerFor($business['email'], $business['business_name'], $index);

            $seller = Seller::query()->updateOrCreate(
                ['user_id' => $owner->id],
                [
                    'type' => $business['type'],
                    'business_name' => $business['business_name'],
                    'slug' => Str::slug($business['business_name']),
                    'registration_number' => $business['registration_number'],
                    'description' => $business['description'],
                    'province_id' => $lusaka->province_id,
                    'city_id' => $lusaka->id,
                    'street' => $business['street'],
                    'plot_number' => (string) (10 + $index),
                    'phone' => $business['phone'],
                    'email' => $business['email'],
                    'contact_person' => $business['contact_person'],
                    'bay_count' => $business['type']->hasWorkshopCapacity() ? 4 : null,
                    'opening_hours' => [
                        'mon' => ['open' => '08:00', 'close' => '17:00'],
                        'tue' => ['open' => '08:00', 'close' => '17:00'],
                        'wed' => ['open' => '08:00', 'close' => '17:00'],
                        'thu' => ['open' => '08:00', 'close' => '17:00'],
                        'fri' => ['open' => '08:00', 'close' => '17:00'],
                        'sat' => ['open' => '08:00', 'close' => '13:00'],
                    ],
                    'verification_status' => $business['status'],
                    'submitted_at' => now()->subDays(10),
                    'verified_at' => $business['status'] === VerificationStatus::Verified ? now()->subDays(3) : null,
                    'rejection_reason' => $business['rejection_reason'] ?? null,
                ],
            );

            $this->givePolicies($seller);
            $this->givePayoutAccount($seller);
        }
    }

    /**
     * The seeded seller account owns the first shop; the rest get their own
     * owner so the "one account, one business" rule still holds.
     */
    private function ownerFor(string $email, string $businessName, int $index): User
    {
        if ($index === 0) {
            $existing = User::query()->where('email', 'seller@monafindauto.test')->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return User::factory()->withRole(Role::Seller)->create([
            'email' => $email,
            'first_name' => Str::before($businessName, ' '),
            'last_name' => 'Owner',
        ]);
    }

    private function givePolicies(Seller $seller): void
    {
        if ($seller->currentPolicies()->exists()) {
            return;
        }

        $this->policies->publishMany($seller, [
            PolicyType::Delivery->value => 'We deliver anywhere in Lusaka within two working days. Delivery outside Lusaka goes by bus and takes three to five days; the buyer pays the bus fare.',
            PolicyType::Refund->value => 'Bring a part back within seven days with its receipt and we will refund it, provided it has not been fitted.',
            PolicyType::Warranty->value => 'New parts carry a three month warranty against manufacturing defects. Used parts carry a fourteen day warranty against failure.',
        ]);
    }

    private function givePayoutAccount(Seller $seller): void
    {
        if ($seller->payoutAccounts()->exists()) {
            return;
        }

        /* The fake gateway resolves any account, so this needs no network. */
        $this->payouts->add($seller, [
            'method' => 'bank',
            'label' => 'Main account',
            'beneficiary_name' => $seller->business_name,
            'account_number' => (string) random_int(1000000000, 9999999999),
            'bank_code' => '01',
            'bank_branch' => 'Cairo Road Branch',
            'is_default' => true,
        ]);
    }

    /**
     * @return array<int, array{type: SellerType, business_name: string, registration_number: string|null, description: string, street: string, phone: string, email: string, contact_person: string, status: VerificationStatus, rejection_reason?: string}>
     */
    private function businesses(): array
    {
        return [
            [
                'type' => SellerType::SparePartsShop,
                'business_name' => 'Kabwata Motor Spares',
                'registration_number' => '120210001234',
                'description' => 'Toyota and Nissan spares over the counter since 2011. If we do not have it we will find it.',
                'street' => 'Burma Road',
                'phone' => '+260967000004',
                'email' => 'seller@monafindauto.test',
                'contact_person' => 'Sipho Seller',
                'status' => VerificationStatus::Verified,
            ],
            [
                'type' => SellerType::CarBreaker,
                'business_name' => 'Chalala Car Breakers',
                'registration_number' => '120180005678',
                'description' => 'We strip accident-damaged vehicles. Every part is used and priced accordingly.',
                'street' => 'Kasama Road',
                'phone' => '+260966000010',
                'email' => 'breaker@monafindauto.test',
                'contact_person' => 'Mwape Zulu',
                'status' => VerificationStatus::Verified,
            ],
            [
                'type' => SellerType::Garage,
                'business_name' => 'Northmead Auto Clinic',
                'registration_number' => '120220009012',
                'description' => 'Servicing, diagnostics and the parts we fit. Four bays, two ramps.',
                'street' => 'Great East Road',
                'phone' => '+260977000011',
                'email' => 'garage@monafindauto.test',
                'contact_person' => 'Bwalya Phiri',
                'status' => VerificationStatus::Submitted,
            ],
            [
                'type' => SellerType::AutoPartsSeller,
                'business_name' => 'Matero Parts Yard',
                /* Waiting in the queue and cannot be badged until a number arrives. */
                'registration_number' => null,
                'description' => 'A yard off the Mumbwa Road. Mostly Toyota, some Mazda.',
                'street' => 'Mumbwa Road',
                'phone' => '+260955000012',
                'email' => 'yard@monafindauto.test',
                'contact_person' => 'Joseph Banda',
                'status' => VerificationStatus::UnderReview,
            ],
            [
                'type' => SellerType::CarDealer,
                'business_name' => 'Roma Motors',
                'registration_number' => '120190003456',
                'description' => 'Used vehicle dealer. We sell the parts that come off our trade-ins.',
                'street' => 'Alick Nkhata Road',
                'phone' => '+260976000013',
                'email' => 'dealer@monafindauto.test',
                'contact_person' => 'Naomi Tembo',
                'status' => VerificationStatus::Rejected,
                'rejection_reason' => 'The registration number did not match PACRA records. Send us the certificate and we will look again.',
            ],
        ];
    }
}
