<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Roles\Role;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
});

it('describes the platform without an account', function () {
    $response = $this->getJson('/api/v1/platform')
        ->assertOk()
        ->assertJsonPath('data.api_version', 'v1')
        ->assertJsonPath('data.currency.code', 'ZMW')
        ->assertJsonPath('data.currency.minor_units_per_major', 100)
        ->assertJsonPath('data.timezone', 'Africa/Lusaka');

    /** Setting keys contain dots, so read the map rather than a JSON path. */
    $settings = $response->json('data.settings');

    expect($settings)->toHaveKey('escrow.pickup_window_days', 3)
        ->and($settings)->not->toHaveKey('monetisation.commission_percent');
});

it('wraps a successful response in a data envelope', function () {
    $response = $this->getJson('/api/v1/platform')->assertOk();

    expect(array_keys($response->json()))->toBe(['data']);
});

it('refuses the account endpoint without a token', function () {
    $this->getJson('/api/v1/account')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('returns the account behind a Sanctum token', function () {
    $user = User::factory()->create(['name' => 'Mutinta Phiri']);
    $user->assignRole(Role::Buyer->value);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/account')
        ->assertOk()
        ->assertJsonPath('data.name', 'Mutinta Phiri')
        ->assertJsonPath('data.roles', ['buyer']);
});

it('answers an unknown endpoint with the error envelope', function () {
    $this->getJson('/api/v1/nowhere')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

it('answers HTML requests to the API with JSON anyway', function () {
    $this->get('/api/v1/nowhere', ['Accept' => 'text/html'])
        ->assertNotFound()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonPath('error.code', 'not_found');
});

it('reports validation failures as field details', function () {
    Route::middleware('api.v1')->prefix('api/v1')->group(function (): void {
        Route::post('probe-validation', function (): void {
            throw ValidationException::withMessages(['quantity' => 'The quantity must be at least 1.']);
        });
    });

    $this->postJson('/api/v1/probe-validation')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.fields.quantity.0', 'The quantity must be at least 1.');
});

it('reports an authorisation failure as forbidden', function () {
    Route::middleware('api.v1')->prefix('api/v1')->group(function (): void {
        Route::get('probe-forbidden', fn () => abort(403, 'Only the seller may do that.'));
    });

    $this->getJson('/api/v1/probe-forbidden')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'request_failed')
        ->assertJsonPath('error.message', 'Only the seller may do that.');
});

it('hides internal details from an unexpected failure when debug is off', function () {
    config()->set('app.debug', false);

    Route::middleware('api.v1')->prefix('api/v1')->group(function (): void {
        Route::get('probe-boom', fn () => throw new RuntimeException('Database on fire'));
    });

    $this->getJson('/api/v1/probe-boom')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'server_error')
        ->assertJsonMissing(['Database on fire']);
});

it('leaves web routes rendering HTML', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertHeaderMissing('x-inertia')
        ->assertSee('<!DOCTYPE html>', escape: false);
});
