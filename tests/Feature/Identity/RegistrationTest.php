<?php

declare(strict_types=1);

use App\Contracts\SmsProvider;
use App\Integrations\Sms\FakeSmsProvider;
use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Models\City;
use App\Modules\Identity\Models\PhoneVerification;
use App\Support\Roles\Role;
use Inertia\Testing\AssertableInertia;

it('creates a pending buyer account and texts it a verification code', function () {
    $sms = app(SmsProvider::class);

    $this->post(route('register.store'), registrationPayload())
        ->assertRedirect(route('phone.verify'));

    $user = User::query()->where('email', 'chanda@example.test')->sole();

    expect($user->name)->toBe('Chanda Mwale')
        ->and($user->phone)->toBe('+260977123456')
        ->and($user->phone_network)->toBe('airtel')
        ->and($user->status)->toBe(AccountStatus::Pending)
        ->and($user->hasVerifiedPhone())->toBeFalse()
        ->and($user->hasRole(Role::Buyer->value))->toBeTrue();

    expect($sms)->toBeInstanceOf(FakeSmsProvider::class)
        ->and($sms->messagesTo('+260977123456'))->toHaveCount(1);

    expect(PhoneVerification::query()->outstanding('+260977123456', OtpPurpose::PhoneVerification)->exists())->toBeTrue();
});

it('records the registration in the audit trail', function () {
    $this->post(route('register.store'), registrationPayload());

    $user = User::query()->where('email', 'chanda@example.test')->sole();

    $entry = AuditLog::query()->where('action', 'user.registered')->sole();

    expect($entry->subject_id)->toBe($user->id)
        ->and($entry->after['via'])->toBe('web');
});

it('stores the number in E.164 whatever shape it was typed in', function (string $typed) {
    $this->post(route('register.store'), registrationPayload(['phone' => $typed]))
        ->assertSessionHasNoErrors();

    expect(User::query()->sole()->phone)->toBe('+260977123456');
})->with([
    'trunk zero' => '0977123456',
    'spaced' => '0977 123 456',
    'international' => '+260977123456',
]);

it('requires every field the platform needs', function () {
    $this->post(route('register.store'), [])
        ->assertSessionHasErrors([
            'first_name',
            'last_name',
            'email',
            'phone',
            'province_id',
            'city_id',
            'street',
            'password',
        ]);

    expect(User::query()->count())->toBe(0);
});

it('rejects a phone number no Zambian network issues', function () {
    $this->post(route('register.store'), registrationPayload(['phone' => '0991234567']))
        ->assertSessionHasErrors(['phone' => 'The phone must be a Zambian mobile number starting 096, 076, 097, 077, 095 or 075.']);
});

it('refuses a number already registered, however it is written', function () {
    User::factory()->create(['phone' => '+260977123456']);

    $this->post(route('register.store'), registrationPayload(['phone' => '0977 123 456']))
        ->assertSessionHasErrors('phone');

    expect(User::query()->count())->toBe(1);
});

it('refuses an email address already registered', function () {
    User::factory()->create(['email' => 'chanda@example.test']);

    $this->post(route('register.store'), registrationPayload())
        ->assertSessionHasErrors('email');
});

it('refuses a city that is not in the chosen province', function () {
    $payload = registrationPayload();
    $elsewhere = City::factory()->create();

    expect($elsewhere->province_id)->not->toBe($payload['province_id']);

    $this->post(route('register.store'), [...$payload, 'city_id' => $elsewhere->id])
        ->assertSessionHasErrors(['city_id' => 'The selected city is not in the selected province.']);
});

it('refuses a province or city that does not exist', function () {
    $this->post(route('register.store'), registrationPayload(['province_id' => 9999, 'city_id' => 9999]))
        ->assertSessionHasErrors(['province_id', 'city_id']);
});

it('requires the password to be confirmed', function () {
    $this->post(route('register.store'), registrationPayload(['password_confirmation' => 'something-else']))
        ->assertSessionHasErrors('password');
});

it('lands a new account on the screen that consumes the code it was sent', function () {
    $this->followingRedirects()
        ->post(route('register.store'), registrationPayload())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/VerifyPhone')
            ->where('phone', '0977 ••• 456')
            ->where('expiryMinutes', 10));
});
