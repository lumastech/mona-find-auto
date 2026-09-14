<?php

declare(strict_types=1);

namespace App\Support\Captcha;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Request;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The captcha as a validation rule, so a failed challenge reads like any
 * other bad field rather than an abort.
 *
 * Put it on the token field of a protected form:
 *
 *     'captcha_token' => CaptchaRule::for('register'),
 *
 * When the deployment does not challenge that form — the local driver, or a
 * form not named in `integrations.captcha.forms` — the rule passes without
 * calling anything, so the same request class serves both.
 *
 * The error attaches to `captcha_token`, which is the field the widget owns.
 * The Vue component watches for it and resets the widget, because a Turnstile
 * token is single-use: re-submitting the same form with the same token would
 * fail a second time and look to the person like the form is broken.
 */
class CaptchaRule implements ValidationRule
{
    /**
     * Run even when the field is absent or empty.
     *
     * This is load-bearing, and its absence was a real hole. Laravel skips an
     * ordinary rule when the attribute is missing, so a bot that simply did
     * not send `captcha_token` would sail past a rule that only ran on tokens
     * that were present — the one case the captcha exists to catch.
     *
     * Laravel reads this property off the rule object (see
     * InvokableValidationRule::make) rather than from an interface.
     */
    public bool $implicit = true;

    private function __construct(private readonly string $form) {}

    /**
     * The rule set for one named form.
     *
     * No `nullable`: it would short-circuit everything after it for exactly
     * the submission that needs checking. `sometimes` is not used either —
     * this rule decides for itself whether the form is challenged, and a
     * missing token on a challenged form must fail.
     *
     * @return array<int, mixed>
     */
    public static function for(string $form): array
    {
        return ['max:4096', new self($form)];
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $guard = app(CaptchaGuard::class);

        if (! $guard->protects($this->form)) {
            return;
        }

        /*
         * Anything that is not a non-empty string is "no challenge was
         * solved" — including the absent field this rule is implicit for.
         */
        $token = is_string($value) && trim($value) !== '' ? $value : null;

        if (! $guard->allows($token, Request::ip(), $this->form)) {
            $fail(__('Please complete the verification challenge and try again.'));
        }
    }
}
