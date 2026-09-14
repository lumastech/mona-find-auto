<?php

use App\Models\User;
use App\Support\Roles\Role;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('staff are sent to the console rather than the buyer dashboard', function () {
    actingAsStaff([Role::PlatformAdmin]);

    $this->get(route('dashboard'))->assertRedirect(route('admin.dashboard'));
});

test('sellers keep the buyer dashboard', function () {
    actingAsRole([Role::Seller]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('storefront/Dashboard'));
});
