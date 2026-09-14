<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Roles\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the platform's roles. Permissions are added by the modules that
 * define them; this seeder only guarantees the roles themselves exist so
 * middleware and policies have something to check against.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Role::cases() as $role) {
            SpatieRole::findOrCreate($role->value, 'web');
        }
    }
}
