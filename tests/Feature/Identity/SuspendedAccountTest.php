<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\UserAddress;
use App\Modules\Identity\Services\AccountModerationService;
use App\Support\Roles\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * The acceptance test behind "a suspended user cannot act anywhere".
 *
 * Suspension has to bite on the very next request, across every area of the
 * platform, whether the person is riding a live session, a remembered cookie
 * or an API token.
 */

it('signs a suspended account out on its next request, whatever it asks for', function (string $route) {
    Notification::fake();

    $user = User::factory()->withRole(Role::Seller, Role::Moderator)->withTwoFactor()->create();

    $this->actingAs($user);

    app(AccountModerationService::class)->suspend($user, 'Selling counterfeit brake pads.');

    $this->get(route($route))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your account is suspended. Reason: Selling counterfeit brake pads.');

    $this->assertGuest();
})->with([
    'storefront dashboard' => 'dashboard',
    'profile settings' => 'profile.edit',
    'delivery addresses' => 'addresses.index',
    'seller portal' => 'seller.dashboard',
    'staff console' => 'admin.dashboard',
]);

it('stops a suspended account from writing anything', function () {
    Notification::fake();

    $user = User::factory()->create();
    $address = UserAddress::factory()->for($user)->create();

    $this->actingAs($user);

    app(AccountModerationService::class)->suspend($user, 'Selling counterfeit brake pads.');

    $this->delete(route('addresses.destroy', $address))->assertRedirect(route('login'));

    expect(UserAddress::query()->whereKey($address->getKey())->exists())->toBeTrue();
});

it('drops the browser sessions a suspended account had open', function () {
    Notification::fake();
    config(['session.driver' => 'database']);

    $user = User::factory()->create();

    DB::table('sessions')->insert([
        'id' => 'other-device-session',
        'user_id' => $user->id,
        'ip_address' => '10.0.0.1',
        'user_agent' => 'Chrome on Android',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    app(AccountModerationService::class)->suspend($user, 'Selling counterfeit brake pads.');

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

it('drops the API tokens a suspended account had issued', function () {
    Notification::fake();

    $user = User::factory()->create();
    $token = $user->createToken('phone')->plainTextToken;

    app(AccountModerationService::class)->suspend($user, 'Selling counterfeit brake pads.');

    expect($user->tokens()->count())->toBe(0);

    $this->withToken($token)
        ->getJson(route('api.v1.auth.me'))
        ->assertUnauthorized();
});

it('forgets the remembered-login cookie so it cannot rebuild a session', function () {
    Notification::fake();

    $user = User::factory()->create();

    expect($user->remember_token)->not->toBeNull();

    app(AccountModerationService::class)->suspend($user, 'Selling counterfeit brake pads.');

    expect($user->refresh()->remember_token)->toBeNull();
});

it('refuses a closed account as well as a suspended one', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    app(AccountModerationService::class)->close($user, 'Closed at the account holder request.');

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your account has been closed. Reason: Closed at the account holder request.');
});
