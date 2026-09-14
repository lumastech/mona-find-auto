<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Roles\Role;

it('signs somebody in with their email address', function () {
    $user = User::factory()->create(['email' => 'buyer@example.test']);

    $this->post(route('login.store'), ['email' => 'buyer@example.test', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('signs somebody in with their phone number', function (string $typed) {
    $user = User::factory()->create(['phone' => '+260977123456']);

    $this->post(route('login.store'), ['email' => $typed, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
})->with([
    'trunk zero' => '0977123456',
    'spaced' => '0977 123 456',
    'international' => '+260977123456',
]);

it('records when the account was last seen', function () {
    $this->freezeTime();

    $user = User::factory()->create(['last_seen_at' => null]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    expect($user->refresh()->last_seen_at)->not->toBeNull();
});

it('refuses a wrong password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('tells a suspended account why it cannot log in', function () {
    $user = User::factory()->suspended('Repeated counterfeit listings.')->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'Your account is suspended. Reason: Repeated counterfeit listings.']);

    $this->assertGuest();
});

it('tells a closed account why it cannot log in', function () {
    $user = User::factory()->closed('Closed on request.')->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'Your account has been closed. Reason: Closed on request.']);

    $this->assertGuest();
});

it('lets a pending account log in so it can finish verifying', function () {
    $user = User::factory()->pending()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

it('challenges a staff account that has two-factor set up', function (string $role) {
    $user = User::factory()->withTwoFactor()->withRole(Role::from($role))->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
})->with([
    Role::Moderator->value,
    Role::Finance->value,
    Role::PlatformAdmin->value,
]);

it('locks out an account being guessed at, even with the right password', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
    }

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertTooManyRequests();

    $this->assertGuest();
});
