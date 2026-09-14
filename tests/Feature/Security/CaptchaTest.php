<?php

declare(strict_types=1);

use App\Contracts\CaptchaVerifier;
use App\Integrations\Captcha\Data\CaptchaResult;
use App\Integrations\Captcha\FakeCaptchaVerifier;
use App\Integrations\Captcha\NullCaptchaVerifier;
use App\Integrations\Captcha\TurnstileVerifier;
use App\Models\User;
use App\Support\Captcha\CaptchaGuard;
use Illuminate\Support\Facades\Http;

/**
 * Bind the fake and make the register form one of the challenged ones.
 */
function challengingCaptcha(): FakeCaptchaVerifier
{
    $fake = new FakeCaptchaVerifier;

    app()->instance(CaptchaVerifier::class, $fake);
    app()->forgetInstance(CaptchaGuard::class);

    config()->set('integrations.captcha.forms', ['register']);

    return $fake;
}

it('challenges nobody by default, so a fresh install has a working signup form', function (): void {
    expect(app(CaptchaVerifier::class))->toBeInstanceOf(NullCaptchaVerifier::class)
        ->and(app(CaptchaGuard::class)->protects('register'))->toBeFalse();

    $this->post(route('register.store'), registrationPayload())
        ->assertSessionHasNoErrors();

    expect(User::query()->count())->toBe(1);
});

it('lets a solved challenge through', function (): void {
    challengingCaptcha();

    $this->post(route('register.store'), registrationPayload(['captcha_token' => 'a-solved-token']))
        ->assertSessionHasNoErrors();

    expect(User::query()->count())->toBe(1);
});

it('refuses a submission with no token when the form is challenged', function (): void {
    challengingCaptcha();

    $this->post(route('register.store'), registrationPayload())
        ->assertSessionHasErrors('captcha_token');

    expect(User::query()->count())->toBe(0);
});

it('refuses a token the provider rejected', function (): void {
    challengingCaptcha()->reject();

    $this->post(route('register.store'), registrationPayload(['captcha_token' => 'forged']))
        ->assertSessionHasErrors('captcha_token');

    expect(User::query()->count())->toBe(0);
});

it('fails open when the provider cannot be reached', function (): void {
    challengingCaptcha()->goOffline();

    /*
     * An outage at Cloudflare must not close MonaFind's registration and
     * password-reset forms. A rejected token is never failed open — only an
     * unanswered one.
     */
    $this->post(route('register.store'), registrationPayload(['captcha_token' => 'anything']))
        ->assertSessionHasNoErrors();

    expect(User::query()->count())->toBe(1);
});

it('can be told to fail closed instead', function (): void {
    challengingCaptcha()->goOffline();

    config()->set('integrations.captcha.fail_open', false);

    $this->post(route('register.store'), registrationPayload(['captcha_token' => 'anything']))
        ->assertSessionHasErrors('captcha_token');

    expect(User::query()->count())->toBe(0);
});

it('does not challenge a form that is not on the list', function (): void {
    challengingCaptcha()->reject();

    config()->set('integrations.captcha.forms', ['password-reset']);

    /*
     * Enabling the driver must not silently put a widget in front of a form
     * with nowhere to render one.
     */
    $this->post(route('register.store'), registrationPayload())
        ->assertSessionHasNoErrors();
});

it('shares the site key with the browser but never the secret', function (): void {
    challengingCaptcha();

    $this->get(route('register'))
        ->assertInertia(fn ($page) => $page
            ->where('captcha.siteKey', 'test-site-key')
            ->where('captcha.forms', ['register']));
});

describe('the Turnstile driver', function (): void {
    it('sends the token and the address, and accepts a success', function (): void {
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response([
                'success' => true,
                'hostname' => 'monafind.co.zm',
            ]),
        ]);

        $result = (new TurnstileVerifier('secret', 'site'))->verify('token', '196.44.1.1');

        expect($result->verified)->toBeTrue();

        Http::assertSent(fn ($request): bool => $request['response'] === 'token'
            && $request['remoteip'] === '196.44.1.1'
            && $request['secret'] === 'secret');
    });

    it('reports a rejection as reachable, so the guard does not fail it open', function (): void {
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response([
                'success' => false,
                'error-codes' => ['invalid-input-response'],
            ]),
        ]);

        $result = (new TurnstileVerifier('secret', 'site'))->verify('token');

        expect($result->verified)->toBeFalse()
            ->and($result->reachable)->toBeTrue()
            ->and($result->errorCodes)->toBe(['invalid-input-response']);
    });

    it('reports a 5xx as unreachable rather than as a rejection', function (): void {
        Http::fake(['challenges.cloudflare.com/*' => Http::response(status: 503)]);

        $result = (new TurnstileVerifier('secret', 'site'))->verify('token');

        expect($result->verified)->toBeFalse()
            ->and($result->reachable)->toBeFalse();
    });

    it('rejects a missing token without calling out at all', function (): void {
        Http::fake();

        $result = (new TurnstileVerifier('secret', 'site'))->verify(null);

        expect($result->verified)->toBeFalse()
            ->and($result->errorCodes)->toBe(['missing-input-response']);

        Http::assertNothingSent();
    });

    it('rejects a token minted for another hostname', function (): void {
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response([
                'success' => true,
                'hostname' => 'somebody-else.example',
            ]),
        ]);

        $result = (new TurnstileVerifier('secret', 'site', expectedHostname: 'monafind.co.zm'))
            ->verify('token');

        expect($result->verified)->toBeFalse()
            ->and($result->errorCodes)->toBe(['hostname-mismatch']);
    });

    it('treats a body it cannot understand as unreachable', function (): void {
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['unexpected' => true])]);

        expect((new TurnstileVerifier('secret', 'site'))->verify('token'))
            ->reachable->toBeFalse();
    });
});

it('describes its own verdict in one word for the log', function (): void {
    expect(CaptchaResult::verified()->reason())->toBe('verified')
        ->and(CaptchaResult::rejected()->reason())->toBe('rejected')
        ->and(CaptchaResult::unreachable()->reason())->toBe('unreachable')
        ->and(CaptchaResult::rejected(['bad-token'])->reason())->toBe('bad-token');
});
