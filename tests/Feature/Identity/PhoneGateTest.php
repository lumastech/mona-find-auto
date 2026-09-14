<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * The phone.verified middleware is registered by the Identity module for the
 * modules that come after it — checkout, seller onboarding, mechanic
 * endorsement all need a number somebody can actually be reached on. It has no
 * route of its own yet, so it is exercised against one defined here.
 */

beforeEach(function () {
    Route::middleware(['web', 'auth', 'phone.verified'])
        ->get('/__test__/needs-phone', fn () => response('ok'))
        ->name('test.needs-phone');
});

it('lets an account with a verified number through', function () {
    $this->actingAs(User::factory()->create())
        ->get('/__test__/needs-phone')
        ->assertOk();
});

it('sends an account with an unverified number to the verification screen', function () {
    $this->actingAs(User::factory()->pending()->create())
        ->get('/__test__/needs-phone')
        ->assertRedirect(route('phone.verify'))
        ->assertSessionHas('status', 'Verify your phone number to continue.');
});

it('refuses an API caller with an unverified number', function () {
    Route::middleware(['api', 'auth:sanctum', 'phone.verified'])
        ->get('/__test__/api-needs-phone', fn () => response('ok'));

    $this->actingAs(User::factory()->pending()->create(), 'sanctum')
        ->getJson('/__test__/api-needs-phone')
        ->assertForbidden();
});
