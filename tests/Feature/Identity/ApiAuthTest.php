<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Enums\AccountStatus;
use App\Support\Roles\Role;
use Laravel\Sanctum\Sanctum;

it('registers an account and hands back a token', function () {
    $response = $this->postJson(route('api.v1.auth.register'), [
        ...registrationPayload(),
        'device_name' => 'Pixel 7a',
    ])
        ->assertCreated()
        ->assertJsonPath('data.user.email', 'chanda@example.test')
        ->assertJsonPath('data.user.status', AccountStatus::Pending->value)
        ->assertJsonPath('data.user.phone', '+260977123456')
        ->assertJsonPath('data.user.roles', [Role::Buyer->value]);

    $user = User::query()->sole();

    expect($user->tokens()->pluck('name')->all())->toBe(['Pixel 7a']);

    /* The token that came back must actually work. */
    $this->withToken($response->json('data.token'))
        ->getJson(route('api.v1.auth.me'))
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);
});

it('reports registration validation failures in the error envelope', function () {
    $this->postJson(route('api.v1.auth.register'), [])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['fields']]]);

    expect(User::query()->count())->toBe(0);
});

it('exchanges an email address or phone number for a token', function (string $field) {
    $user = User::factory()->create(['email' => 'buyer@example.test', 'phone' => '+260977123456']);

    $login = $field === 'email' ? 'buyer@example.test' : '0977123456';

    $this->postJson(route('api.v1.auth.login'), ['login' => $login, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonStructure(['data' => ['token']]);
})->with(['email', 'phone']);

it('gives nothing away when the credentials are wrong', function (array $payload) {
    User::factory()->create(['email' => 'buyer@example.test']);

    $this->postJson(route('api.v1.auth.login'), $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error.details.fields.login', ['Those credentials do not match our records.']);
})->with([
    'wrong password' => [['login' => 'buyer@example.test', 'password' => 'wrong-password']],
    'unknown account' => [['login' => 'nobody@example.test', 'password' => 'password']],
]);

it('refuses a token to a suspended account and says why', function () {
    $user = User::factory()->suspended('Selling counterfeit brake pads.')->create();

    $this->postJson(route('api.v1.auth.login'), ['login' => $user->email, 'password' => 'password'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'account_blocked')
        ->assertJsonPath('error.message', 'Your account is suspended. Reason: Selling counterfeit brake pads.');

    expect($user->tokens()->count())->toBe(0);
});

it('names an unnamed token after the calling device', function () {
    $user = User::factory()->create();

    $this->withHeader('User-Agent', 'MonaFind/1.0 (Android 13)')
        ->postJson(route('api.v1.auth.login'), ['login' => $user->email, 'password' => 'password'])
        ->assertOk();

    expect($user->tokens()->sole()->name)->toBe('MonaFind/1.0 (Android 13)');
});

it('drops only the token the request was made with on logout', function () {
    $user = User::factory()->create();
    $keep = $user->createToken('other phone');
    $current = $user->createToken('this phone');

    $this->withToken($current->plainTextToken)
        ->postJson(route('api.v1.auth.logout'))
        ->assertNoContent();

    expect($user->tokens()->pluck('id')->all())->toBe([$keep->accessToken->id]);
});

it('needs a token for the endpoints behind one', function (string $method, string $route) {
    $this->json($method, route($route))->assertUnauthorized();
})->with([
    'me' => ['get', 'api.v1.auth.me'],
    'logout' => ['post', 'api.v1.auth.logout'],
    'addresses' => ['get', 'api.v1.addresses.index'],
]);

it('describes the account behind the token', function () {
    $user = User::factory()->withRole(Role::Buyer, Role::Mechanic)->create(['phone' => '+260977123456']);

    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.auth.me'))
        ->assertOk()
        ->assertJsonPath('data.phone_national', '0977 123 456')
        ->assertJsonPath('data.phone_network', 'airtel')
        ->assertJsonPath('data.roles', [Role::Buyer->value, Role::Mechanic->value])
        ->assertJsonMissingPath('data.password');
});
