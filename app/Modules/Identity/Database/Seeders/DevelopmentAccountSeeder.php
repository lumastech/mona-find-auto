<?php

declare(strict_types=1);

namespace App\Modules\Identity\Database\Seeders;

use App\Models\User;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Models\City;
use App\Modules\Identity\Models\UserAddress;
use App\Modules\Identity\Support\ZambianPhone;
use App\Support\Roles\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One account per role for local development.
 *
 * The credentials are documented in the README. This only ever runs outside
 * production — DatabaseSeeder guards the call — but the passwords are
 * deliberately obvious so nobody is tempted to reuse the pattern for real.
 */
class DevelopmentAccountSeeder extends Seeder
{
    /** The password every seeded development account shares. */
    public const PASSWORD = 'password';

    public function run(): void
    {
        $lusaka = City::query()->where('slug', 'lusaka')->first();

        foreach ($this->accounts() as $account) {
            $user = User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'first_name' => $account['first_name'],
                    'last_name' => $account['last_name'],
                    /* Set explicitly: DatabaseSeeder mutes the model events that keep it in step. */
                    'name' => $account['first_name'].' '.$account['last_name'],
                    'phone' => $account['phone'],
                    'phone_network' => ZambianPhone::tryParse($account['phone'])?->network->value,
                    /* Set explicitly for the same reason as the name above. */
                    'phone_verified_at' => now(),
                    'email_verified_at' => now(),
                    'password' => Hash::make(self::PASSWORD),
                    'status' => AccountStatus::Active,
                    'status_changed_at' => now(),
                    'province_id' => $lusaka?->province_id,
                    'city_id' => $lusaka?->id,
                    'street' => 'Cairo Road',
                    'plot_number' => (string) (100 + count($account['roles'])),
                ],
            );

            $user->syncRoles(array_map(static fn (Role $role): string => $role->value, $account['roles']));

            $this->giveDeliveryAddress($user, $lusaka);
        }
    }

    /**
     * Every seeded account can check out, which means every one needs an
     * address to check out to.
     */
    private function giveDeliveryAddress(User $user, ?City $city): void
    {
        if ($city === null || $user->addresses()->exists()) {
            return;
        }

        UserAddress::query()->create([
            'user_id' => $user->id,
            'label' => 'Home',
            'recipient_name' => $user->name,
            'recipient_phone' => $user->phone,
            'province_id' => $city->province_id,
            'city_id' => $city->id,
            'street' => 'Great East Road',
            'plot_number' => '42',
            'is_default' => true,
        ]);
    }

    /**
     * @return array<int, array{first_name: string, last_name: string, email: string, phone: string, roles: array<int, Role>}>
     */
    private function accounts(): array
    {
        return [
            [
                'first_name' => 'Mona',
                'last_name' => 'Admin',
                'email' => 'admin@monafindauto.test',
                'phone' => '+260977000001',
                'roles' => [Role::PlatformAdmin, Role::Buyer],
            ],
            [
                'first_name' => 'Mercy',
                'last_name' => 'Moderator',
                'email' => 'moderator@monafindauto.test',
                'phone' => '+260977000002',
                'roles' => [Role::Moderator, Role::Buyer],
            ],
            [
                'first_name' => 'Fred',
                'last_name' => 'Finance',
                'email' => 'finance@monafindauto.test',
                'phone' => '+260977000003',
                'roles' => [Role::Finance, Role::Buyer],
            ],
            [
                'first_name' => 'Sipho',
                'last_name' => 'Seller',
                'email' => 'seller@monafindauto.test',
                'phone' => '+260967000004',
                'roles' => [Role::Seller, Role::Buyer],
            ],
            [
                'first_name' => 'Sarah',
                'last_name' => 'Shopfloor',
                'email' => 'seller-staff@monafindauto.test',
                'phone' => '+260967000005',
                'roles' => [Role::SellerStaff],
            ],
            [
                'first_name' => 'Mulenga',
                'last_name' => 'Mechanic',
                'email' => 'mechanic@monafindauto.test',
                'phone' => '+260957000006',
                /* A mechanic who also buys parts — the common case. */
                'roles' => [Role::Mechanic, Role::Buyer],
            ],
            [
                'first_name' => 'Bwalya',
                'last_name' => 'Buyer',
                'email' => 'buyer@monafindauto.test',
                'phone' => '+260957000007',
                'roles' => [Role::Buyer],
            ],
        ];
    }
}
