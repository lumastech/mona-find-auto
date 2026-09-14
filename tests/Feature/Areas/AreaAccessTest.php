<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Roles\Role;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('lets a guest browse the storefront', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('storefront/Home'));
});

it('shares the currency and timezone with every page', function () {
    $this->get(route('home'))->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('platform.currency.code', 'ZMW')
            ->where('platform.currency.minor_units_per_major', 100)
            ->where('platform.timezone', 'Africa/Lusaka')
    );
});

it('tells a page which areas the visitor may open', function () {
    $seller = User::factory()->create();
    $seller->assignRole(Role::Seller->value);

    $this->actingAs($seller)->get(route('home'))->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('auth.isSeller', true)
            ->where('auth.isStaff', false)
            ->where('auth.roles', ['seller'])
    );
});

it('reports a guest as belonging to no area', function () {
    $this->get(route('home'))->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('auth.user', null)
            ->where('auth.isSeller', false)
            ->where('auth.isStaff', false)
    );
});

it('sends a guest from the seller portal to the login page', function () {
    $this->get(route('seller.dashboard'))->assertRedirect(route('login'));
});

it('refuses the seller portal to an account without a seller role', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('seller.dashboard'))
        ->assertForbidden();
});

it('opens the seller portal for a seller', function () {
    $seller = User::factory()->create();
    $seller->assignRole(Role::Seller->value);

    $this->actingAs($seller)
        ->get(route('seller.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('seller/Dashboard'));
});

it('opens the seller portal for seller staff', function () {
    $staff = User::factory()->create();
    $staff->assignRole(Role::SellerStaff->value);

    $this->actingAs($staff)->get(route('seller.dashboard'))->assertOk();
});

it('sends a guest from the staff console to the login page', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

it('refuses the staff console to a seller', function () {
    $seller = User::factory()->create();
    $seller->assignRole(Role::Seller->value);

    $this->actingAs($seller)->get(route('admin.dashboard'))->assertForbidden();
});

it('opens the staff console for every staff role', function (string $role) {
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole($role);

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/Dashboard'));
})->with([
    Role::Moderator->value,
    Role::Finance->value,
    Role::PlatformAdmin->value,
]);

it('keeps the Horizon dashboard for platform admins only', function () {
    $staff = User::factory()->create();
    $staff->assignRole(Role::Moderator->value);

    $admin = User::factory()->create();
    $admin->assignRole(Role::PlatformAdmin->value);

    expect(Gate::forUser($staff)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue();
});
