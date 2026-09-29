<?php

declare(strict_types=1);

use App\Contracts\SmsProvider;
use App\Models\User;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Models\City;
use App\Modules\Identity\Models\PhoneVerification;
use App\Modules\Identity\Notifications\WelcomeNotification;
use App\Modules\Identity\Support\PhoneVerificationGate;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/*
 * MonaFind has no SMS gateway yet, so PhoneVerificationGate is off by
 * default and this is how the platform actually behaves today: numbers are
 * collected and validated, no code is sent, and a verified EMAIL address is
 * what takes an account out of Pending.
 *
 * The OTP flow these cases stand in for is not deleted — it is covered with
 * the gate switched on (PhoneVerificationTest, RegistrationTest) so that
 * turning the flag on is all the gateway's arrival takes.
 */

it('is the default for this deployment', function () {
    expect(PhoneVerificationGate::enabled())->toBeFalse();
});

it('registers an account with its number but sends no code', function () {
    $this->post(route('register.store'), registrationPayload())
        ->assertRedirect(route('verification.notice'));

    $user = User::query()->where('email', 'chanda@example.test')->sole();

    expect($user->phone)->toBe('+260977123456')
        ->and($user->phone_network)->toBe('airtel')
        ->and($user->status)->toBe(AccountStatus::Pending)
        ->and($user->hasVerifiedPhone())->toBeFalse();

    expect(app(SmsProvider::class)->messagesTo('+260977123456'))->toBeEmpty()
        ->and(PhoneVerification::query()->count())->toBe(0);
});

it('still refuses a number that is not a Zambian mobile', function () {
    $this->post(route('register.store'), registrationPayload(['phone' => '0991234567']))
        ->assertSessionHasErrors('phone');

    expect(User::query()->count())->toBe(0);
});

it('activates a pending account when it verifies its email address', function () {
    $user = User::factory()->pending()->unverified()->create();

    $this->actingAs($user)->get(verificationUrlFor($user))->assertRedirect();

    $user->refresh();

    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->status)->toBe(AccountStatus::Active)
        ->and($user->status_reason)->toBe('Email address verified.')
        ->and($user->hasVerifiedPhone())->toBeFalse();
});

it('leaves activation to the phone code once the gateway is live', function () {
    withPhoneVerification();

    $user = User::factory()->pending()->unverified()->create();

    $this->actingAs($user)->get(verificationUrlFor($user))->assertRedirect();

    expect($user->refresh()->email_verified_at)->not->toBeNull()
        ->and($user->status)->toBe(AccountStatus::Pending);
});

it('sends anybody who reaches the verification screen to the dashboard', function () {
    $user = User::factory()->pending()->create();

    $this->actingAs($user)->get(route('phone.verify'))->assertRedirect(route('dashboard'));
    $this->actingAs($user)->post(route('phone.resend'))->assertRedirect(route('dashboard'));
    $this->actingAs($user)
        ->post(route('phone.verify.submit'), ['code' => '123456'])
        ->assertRedirect(route('dashboard'));

    expect(app(SmsProvider::class)->messagesTo($user->phone))->toBeEmpty();
});

it('lets an unproven number past the phone gate', function () {
    Route::middleware(['web', 'auth', 'phone.verified'])
        ->get('/__test__/needs-phone', fn () => response('ok'));

    $this->actingAs(User::factory()->pending()->create())
        ->get('/__test__/needs-phone')
        ->assertOk();
});

it('closes password reset by SMS, leaving the email reset', function () {
    User::factory()->create(['phone' => '+260977123456']);

    $this->get(route('password.sms.request'))->assertNotFound();
    $this->post(route('password.sms.send'), ['phone' => '0977123456'])->assertNotFound();
    $this->post(route('password.sms.update'), [
        'phone' => '0977123456',
        'code' => '123456',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertNotFound();

    /*
     * `password.sms.reset` (GET reset-password/sms) is deliberately absent:
     * that URI is shadowed by Fortify's own reset-password/{token} route,
     * which reads "sms" as a token. It has nothing to do with the gateway.
     */

    expect(app(SmsProvider::class)->messagesTo('+260977123456'))->toBeEmpty();

    $this->get(route('password.request'))->assertOk();
});

it('finishes a social signup without a code', function () {
    config([
        'services.google.client_id' => 'google-client-id',
        'services.google.client_secret' => 'google-client-secret',
    ]);

    Socialite::fake('google', SocialiteUser::fake([
        'id' => '1234567890',
        'name' => 'Chanda Mwale',
        'email' => 'chanda@example.test',
    ]));

    /* The provider proved the address, so there is nothing left to wait for. */
    $this->get(route('social.callback', 'google'))->assertRedirect(route('phone.setup'));

    $user = User::query()->sole();

    expect($user->status)->toBe(AccountStatus::Active)
        ->and($user->phone)->toBeNull();

    $city = City::query()->first() ?? City::factory()->create();

    $this->actingAs($user)
        ->post(route('phone.setup.store'), [
            'phone' => '0977123456',
            'province_id' => $city->province_id,
            'city_id' => $city->id,
            'street' => 'Cairo Road',
        ])
        ->assertRedirect(route('dashboard'));

    expect($user->refresh()->phone)->toBe('+260977123456')
        ->and($user->hasVerifiedPhone())->toBeFalse()
        ->and(app(SmsProvider::class)->messagesTo('+260977123456'))->toBeEmpty();

    /* And with a number on file, the setup screen has nothing left to ask. */
    $this->actingAs($user)->get(route('phone.setup'))->assertRedirect(route('dashboard'));
});

describe('the JSON API', function () {
    it('reports that there is nothing to verify', function () {
        $this->actingAs(User::factory()->pending()->create(), 'sanctum')
            ->getJson(route('api.v1.phone.show'))
            ->assertOk()
            ->assertJsonPath('data.verified', false)
            ->assertJsonPath('data.verification_enabled', false);
    });

    it('records a number without sending a code', function () {
        $user = User::factory()->create(['phone' => null, 'phone_verified_at' => null]);

        $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.phone.store'), ['phone' => '0977123456'])
            ->assertOk()
            ->assertJsonPath('data.sent', false);

        expect($user->refresh()->phone)->toBe('+260977123456')
            ->and(app(SmsProvider::class)->messagesTo('+260977123456'))->toBeEmpty();
    });

    it('answers 503 where a delivered code is the whole point', function () {
        $user = User::factory()->pending()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.phone.resend'))
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'phone_verification_unavailable');

        $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.phone.verify'), ['code' => '123456'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'phone_verification_unavailable');
    });
});

it('drops the phone line from the welcome message', function () {
    $user = User::factory()->pending()->unverified()->create();

    $payload = (new WelcomeNotification($user))->toArray($user);

    expect($payload['body'])->not->toContain('six-digit code')
        ->toContain('Confirm your email address');
});

it('tells the browser the gateway is off', function () {
    $this->get(route('login'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('platform.phoneVerification', false));
});

/**
 * The signed link Fortify mails out.
 */
function verificationUrlFor(User $user): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->getKey(),
        'hash' => sha1($user->email),
    ]);
}
