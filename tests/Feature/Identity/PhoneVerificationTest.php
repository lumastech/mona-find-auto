<?php

declare(strict_types=1);

use App\Contracts\SmsProvider;
use App\Models\User;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Enums\OtpVerificationResult;
use App\Modules\Identity\Exceptions\OtpThrottled;
use App\Modules\Identity\Models\PhoneVerification;
use App\Modules\Identity\Services\OtpService;

it('activates a pending account once the code is right', function () {
    $user = User::factory()->pending()->create(['phone' => '+260977123456']);
    PhoneVerification::factory()->for($user)->create(['phone' => $user->phone]);

    $this->actingAs($user)
        ->post(route('phone.verify.submit'), ['code' => '123456'])
        ->assertRedirect(route('dashboard'));

    $user->refresh();

    expect($user->hasVerifiedPhone())->toBeTrue()
        ->and($user->status)->toBe(AccountStatus::Active)
        ->and($user->phone_network)->toBe('airtel');
});

it('rejects a code that is not correct and counts the attempt', function () {
    $user = User::factory()->pending()->create(['phone' => '+260977123456']);
    $verification = PhoneVerification::factory()->for($user)->create(['phone' => $user->phone]);

    $this->actingAs($user)
        ->post(route('phone.verify.submit'), ['code' => '000000'])
        ->assertSessionHasErrors(['code' => OtpVerificationResult::Invalid->message()]);

    expect($verification->refresh()->attempts)->toBe(1)
        ->and($user->refresh()->hasVerifiedPhone())->toBeFalse();
});

it('burns the code after three wrong guesses', function () {
    $user = User::factory()->pending()->create(['phone' => '+260977123456']);
    $verification = PhoneVerification::factory()->for($user)->create(['phone' => $user->phone]);

    $otp = app(OtpService::class);

    expect($otp->verify($user->phone, OtpPurpose::PhoneVerification, '000000'))->toBe(OtpVerificationResult::Invalid)
        ->and($otp->verify($user->phone, OtpPurpose::PhoneVerification, '000000'))->toBe(OtpVerificationResult::Invalid)
        ->and($otp->verify($user->phone, OtpPurpose::PhoneVerification, '000000'))->toBe(OtpVerificationResult::TooManyAttempts);

    /* The real code no longer works either — the code itself is spent. */
    expect($otp->verify($user->phone, OtpPurpose::PhoneVerification, '123456'))->toBe(OtpVerificationResult::NotFound)
        ->and($verification->refresh()->isConsumed())->toBeTrue();
});

it('refuses a code that has expired', function () {
    $user = User::factory()->pending()->create(['phone' => '+260977123456']);
    PhoneVerification::factory()->for($user)->expired()->create(['phone' => $user->phone]);

    $this->actingAs($user)
        ->post(route('phone.verify.submit'), ['code' => '123456'])
        ->assertSessionHasErrors(['code' => OtpVerificationResult::Expired->message()]);

    expect($user->refresh()->hasVerifiedPhone())->toBeFalse();
});

it('still accepts a code inside its ten-minute window', function () {
    $this->freezeTime();

    $user = User::factory()->pending()->create(['phone' => '+260977123456']);

    $otp = app(OtpService::class);
    $otp->issue($user->phone, OtpPurpose::PhoneVerification, $user);

    $code = codeSentTo($user->phone);

    $this->travel(9)->minutes();

    expect($otp->verify($user->phone, OtpPurpose::PhoneVerification, $code))->toBe(OtpVerificationResult::Verified);
});

it('refuses a code once the window has passed', function () {
    $this->freezeTime();

    $user = User::factory()->pending()->create(['phone' => '+260977123456']);
    $otp = app(OtpService::class);
    $otp->issue($user->phone, OtpPurpose::PhoneVerification, $user);
    $code = codeSentTo($user->phone);

    $this->travel(11)->minutes();

    expect($otp->verify($user->phone, OtpPurpose::PhoneVerification, $code))->toBe(OtpVerificationResult::Expired);
});

it('will not send another code until the resend window has passed', function () {
    $this->freezeTime();

    $user = User::factory()->pending()->create(['phone' => '+260977123456']);
    $otp = app(OtpService::class);

    $otp->issue($user->phone, OtpPurpose::PhoneVerification, $user);

    expect(fn () => $otp->issue($user->phone, OtpPurpose::PhoneVerification, $user))
        ->toThrow(OtpThrottled::class);

    $this->travel(60)->seconds();

    $otp->issue($user->phone, OtpPurpose::PhoneVerification, $user);

    expect(app(SmsProvider::class)->messagesTo($user->phone))->toHaveCount(2);
});

it('tells somebody how long to wait rather than silently failing', function () {
    $this->freezeTime();

    $user = User::factory()->pending()->create(['phone' => '+260977123456']);
    app(OtpService::class)->issue($user->phone, OtpPurpose::PhoneVerification, $user);

    $this->actingAs($user)
        ->post(route('phone.resend'))
        ->assertSessionHasErrors(['code' => 'Wait 60 more seconds before requesting another code.']);
});

it('replaces the outstanding code when a new one is sent', function () {
    $this->freezeTime();

    $user = User::factory()->pending()->create(['phone' => '+260977123456']);
    $otp = app(OtpService::class);

    $otp->issue($user->phone, OtpPurpose::PhoneVerification, $user);
    $first = codeSentTo($user->phone);

    $this->travel(60)->seconds();
    $otp->issue($user->phone, OtpPurpose::PhoneVerification, $user);

    expect($otp->verify($user->phone, OtpPurpose::PhoneVerification, $first))->toBe(OtpVerificationResult::Invalid);
});

it('never accepts a password-reset code as phone verification', function () {
    $user = User::factory()->pending()->create(['phone' => '+260977123456']);
    PhoneVerification::factory()->for($user)->forPasswordReset()->create(['phone' => $user->phone]);

    expect(app(OtpService::class)->verify($user->phone, OtpPurpose::PhoneVerification, '123456'))
        ->toBe(OtpVerificationResult::NotFound);
});

it('sends a verified account away from the verification screen', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('phone.verify'))
        ->assertRedirect(route('dashboard'));
});

it('keeps the verification screen behind a login', function () {
    $this->get(route('phone.verify'))->assertRedirect(route('login'));
});
