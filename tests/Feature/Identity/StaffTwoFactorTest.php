<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Roles\Role;
use Inertia\Testing\AssertableInertia as Assert;

it('sends a staff account without two-factor to the setup screen', function (string $role) {
    $user = User::factory()->withRole(Role::from($role))->create();

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('status', 'Two-factor authentication is required for staff accounts. Set it up to continue.');
})->with([
    Role::Moderator->value,
    Role::Finance->value,
    Role::PlatformAdmin->value,
]);

it('holds an unenrolled staff account out of every other area too', function (string $route) {
    $user = User::factory()->withRole(Role::Moderator, Role::Seller)->create();

    $this->actingAs($user)
        ->get(route($route))
        ->assertRedirect(route('security.edit'));
})->with([
    'storefront dashboard' => 'dashboard',
    'delivery addresses' => 'addresses.index',
    'seller portal' => 'seller.dashboard',
]);

it('leaves the enrolment path itself open', function () {
    $user = User::factory()->withRole(Role::Moderator)->create();

    /* Password confirmation guards the security screen; it must not be blocked. */
    $this->actingAs($user)
        ->get(route('security.edit'))
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($user)
        ->get(route('password.confirm'))
        ->assertOk();
});

it('opens the console once two-factor is confirmed', function () {
    $user = User::factory()->withTwoFactor()->withRole(Role::Moderator)->create();

    $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
});

it('does not accept a secret that was never confirmed', function () {
    $user = User::factory()->withTwoFactor()->withRole(Role::Moderator)->create([
        'two_factor_confirmed_at' => null,
    ]);

    expect($user->hasConfirmedTwoFactor())->toBeFalse();

    $this->actingAs($user)->get(route('admin.dashboard'))->assertRedirect(route('security.edit'));
});

it('asks nothing extra of a buyer, seller or mechanic', function (Role $role) {
    $user = User::factory()->withRole($role)->create();

    expect($user->requiresTwoFactor())->toBeFalse()
        ->and($user->mustEnrolInTwoFactor())->toBeFalse();
})->with([
    'buyer' => Role::Buyer,
    'seller' => Role::Seller,
    'seller staff' => Role::SellerStaff,
    'mechanic' => Role::Mechanic,
]);

it('keeps a staff account without two-factor out of the API as well', function () {
    $user = User::factory()->withRole(Role::Finance)->create();

    $this->postJson(route('api.v1.auth.login'), [
        'login' => $user->email,
        'password' => 'password',
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'two_factor_required');
});

it('tells an unenrolled staff account what to do on the setup screen', function () {
    $user = User::factory()->withRole(Role::PlatformAdmin)->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Security')
            ->where('mustEnrolInTwoFactor', true),
        );
});

it('drops the notice once the staff account has enrolled', function () {
    $user = User::factory()->withTwoFactor()->withRole(Role::PlatformAdmin)->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('mustEnrolInTwoFactor', false));
});

it('shows no notice to a buyer, who is never asked to enrol', function () {
    $user = User::factory()->withRole(Role::Buyer)->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('mustEnrolInTwoFactor', false));
});
