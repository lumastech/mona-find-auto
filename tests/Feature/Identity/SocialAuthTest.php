<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Models\SocialAccount;
use App\Support\Roles\Role;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config([
        'services.google.client_id' => 'google-client-id',
        'services.google.client_secret' => 'google-client-secret',
        'services.facebook.client_id' => 'facebook-client-id',
        'services.facebook.client_secret' => 'facebook-client-secret',
    ]);
});

/**
 * A Socialite profile as Google or Facebook would hand it back.
 */
function oauthUser(array $overrides = []): SocialiteUser
{
    return SocialiteUser::fake([
        'id' => '1234567890',
        'name' => 'Chanda Mwale',
        'email' => 'chanda@example.test',
        'nickname' => 'chanda',
        'avatar' => 'https://example.test/avatar.jpg',
        ...$overrides,
    ]);
}

it('creates a pending account and asks it for a phone number', function (string $provider) {
    Socialite::fake($provider, oauthUser());

    $this->get(route('social.callback', $provider))
        ->assertRedirect(route('phone.setup'));

    $user = User::query()->sole();

    expect($user->email)->toBe('chanda@example.test')
        ->and($user->first_name)->toBe('Chanda')
        ->and($user->last_name)->toBe('Mwale')
        ->and($user->status)->toBe(AccountStatus::Pending)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->hasRole(Role::Buyer->value))->toBeTrue()
        ->and($user->socialAccounts()->sole()->provider)->toBe($provider);

    $this->assertAuthenticatedAs($user);
})->with(['google', 'facebook']);

it('links the identity to an account that already uses that email address', function () {
    $user = User::factory()->create(['email' => 'chanda@example.test']);

    Socialite::fake('google', oauthUser());

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('dashboard'));

    expect(User::query()->count())->toBe(1)
        ->and($user->socialAccounts()->sole()->provider_id)->toBe('1234567890');

    $this->assertAuthenticatedAs($user);
});

it('signs an already-linked identity straight back in', function () {
    $user = User::factory()->create();
    SocialAccount::factory()->for($user)->create([
        'provider' => 'google',
        'provider_id' => '1234567890',
    ]);

    Socialite::fake('google', oauthUser(['email' => 'a-different@example.test']));

    $this->get(route('social.callback', 'google'));

    expect(User::query()->count())->toBe(1);

    $this->assertAuthenticatedAs($user);
});

it('lets one account link both providers', function () {
    $user = User::factory()->create(['email' => 'chanda@example.test']);

    Socialite::fake('google', oauthUser(['id' => 'google-1']));
    $this->get(route('social.callback', 'google'));

    $this->post(route('logout'));

    Socialite::fake('facebook', oauthUser(['id' => 'facebook-1']));
    $this->get(route('social.callback', 'facebook'));

    expect($user->socialAccounts()->pluck('provider')->sort()->values()->all())
        ->toBe(['facebook', 'google']);
});

it('invents a unique address when the provider withholds the email', function () {
    Socialite::fake('facebook', oauthUser(['email' => null]));

    $this->get(route('social.callback', 'facebook'));

    $user = User::query()->sole();

    expect($user->email)->toBe('facebook-1234567890@social.monafindauto.invalid')
        ->and($user->email_verified_at)->toBeNull();
});

it('refuses to sign a suspended account in through a provider', function () {
    $user = User::factory()->suspended('Selling counterfeit brake pads.')->create([
        'email' => 'chanda@example.test',
    ]);

    Socialite::fake('google', oauthUser());

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your account is suspended. Reason: Selling counterfeit brake pads.');

    $this->assertGuest();
});

it('sends a verified account straight to its dashboard', function () {
    $user = User::factory()->create(['email' => 'chanda@example.test']);

    Socialite::fake('google', oauthUser());

    $this->get(route('social.callback', 'google'))->assertRedirect(route('dashboard'));

    expect($user->refresh()->hasVerifiedPhone())->toBeTrue();
});

it('hides a provider this deployment has no credentials for', function () {
    config(['services.facebook.client_id' => null]);

    $this->get(route('social.redirect', 'facebook'))->assertNotFound();
    $this->get(route('social.callback', 'facebook'))->assertNotFound();
});

it('refuses a provider MonaFind does not support at all', function () {
    $this->get(route('social.redirect', 'twitter'))->assertNotFound();
});

it('hands the visitor off to the provider', function () {
    $this->get(route('social.redirect', 'google'))
        ->assertRedirectContains('accounts.google.com');
});
