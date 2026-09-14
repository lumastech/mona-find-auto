<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\UserAddress;
use App\Support\Roles\Role;
use Illuminate\Support\Facades\Gate;

it('lets only staff roles browse accounts', function (Role $role, bool $allowed) {
    $user = User::factory()->withRole($role)->create();

    expect(Gate::forUser($user)->allows('viewAny', User::class))->toBe($allowed);
})->with([
    'buyer' => [Role::Buyer, false],
    'seller' => [Role::Seller, false],
    'seller staff' => [Role::SellerStaff, false],
    'mechanic' => [Role::Mechanic, false],
    'moderator' => [Role::Moderator, true],
    'finance' => [Role::Finance, true],
    'platform admin' => [Role::PlatformAdmin, true],
]);

it('lets only staff roles moderate an account', function (Role $role, bool $allowed) {
    $user = User::factory()->withRole($role)->create();
    $subject = User::factory()->create();

    expect(Gate::forUser($user)->allows('moderate', $subject))->toBe($allowed);
})->with([
    'buyer' => [Role::Buyer, false],
    'seller' => [Role::Seller, false],
    'mechanic' => [Role::Mechanic, false],
    'moderator' => [Role::Moderator, true],
    'finance' => [Role::Finance, true],
    'platform admin' => [Role::PlatformAdmin, true],
]);

it('never lets staff moderate themselves', function () {
    $staff = User::factory()->withRole(Role::Moderator)->create();

    expect(Gate::forUser($staff)->allows('moderate', $staff))->toBeFalse();
});

it('reserves suspending a platform administrator for another platform administrator', function () {
    $admin = User::factory()->withRole(Role::PlatformAdmin)->create();
    $otherAdmin = User::factory()->withRole(Role::PlatformAdmin)->create();
    $moderator = User::factory()->withRole(Role::Moderator)->create();

    expect(Gate::forUser($moderator)->allows('moderate', $admin))->toBeFalse()
        ->and(Gate::forUser($otherAdmin)->allows('moderate', $admin))->toBeTrue();
});

it('reserves handing out roles for platform administrators', function (Role $role, bool $allowed) {
    $user = User::factory()->withRole($role)->create();

    expect(Gate::forUser($user)->allows('assignRoles', User::factory()->create()))->toBe($allowed);
})->with([
    'moderator' => [Role::Moderator, false],
    'finance' => [Role::Finance, false],
    'platform admin' => [Role::PlatformAdmin, true],
]);

it('keeps a delivery address private to the buyer who saved it', function () {
    $owner = User::factory()->create();
    $address = UserAddress::factory()->for($owner)->create();

    $stranger = User::factory()->create();
    $staff = User::factory()->withRole(Role::PlatformAdmin)->create();

    foreach (['view', 'update', 'delete'] as $ability) {
        expect(Gate::forUser($owner)->allows($ability, $address))->toBeTrue()
            ->and(Gate::forUser($stranger)->allows($ability, $address))->toBeFalse()
            ->and(Gate::forUser($staff)->allows($ability, $address))->toBeFalse();
    }
});

it('answers the platform gates other modules check', function (string $gate, Role $role, bool $allowed) {
    $user = User::factory()->withRole($role)->create();

    expect(Gate::forUser($user)->allows($gate))->toBe($allowed);
})->with([
    'staff gate, moderator' => ['staff', Role::Moderator, true],
    'staff gate, buyer' => ['staff', Role::Buyer, false],
    'admin-only gate, platform admin' => ['admin-only', Role::PlatformAdmin, true],
    'admin-only gate, moderator' => ['admin-only', Role::Moderator, false],
    'finance gate, finance' => ['finance', Role::Finance, true],
    'finance gate, moderator' => ['finance', Role::Moderator, false],
    'moderate gate, moderator' => ['moderate', Role::Moderator, true],
    'moderate gate, finance' => ['moderate', Role::Finance, false],
    'sell gate, seller staff' => ['sell', Role::SellerStaff, true],
    'sell gate, buyer' => ['sell', Role::Buyer, false],
]);
