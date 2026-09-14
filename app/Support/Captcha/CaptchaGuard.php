<?php

declare(strict_types=1);

namespace App\Support\Captcha;

use App\Contracts\CaptchaVerifier;
use App\Integrations\Captcha\Data\CaptchaResult;
use Illuminate\Support\Facades\Log;

/**
 * The policy around the captcha, kept apart from the thing that talks to
 * Cloudflare.
 *
 * The verifier answers "is this token good". This class answers the question
 * the application actually has: "should this submission be allowed through".
 * They differ in two places, and both are deliberate.
 *
 * ## Fail open on an outage
 *
 * If Cloudflare cannot be reached, the submission is allowed and the incident
 * is logged. The alternative — refusing every registration and every password
 * reset for the duration of somebody else's outage — trades a small, bounded
 * spam risk for a total loss of the front door. `captcha.fail_open` turns this
 * off for a deployment that would rather close the door.
 *
 * A rejected token is never failed open. Only an unanswered one is.
 *
 * ## Protected forms are named, not guessed
 *
 * `config('integrations.captcha.forms')` lists the forms that carry a
 * challenge. A form not on the list is not challenged even when the driver is
 * enabled, so switching Turnstile on does not silently put a widget in front
 * of a form that has no place to render one.
 */
class CaptchaGuard
{
    public function __construct(private readonly CaptchaVerifier $verifier) {}

    /**
     * Does `$form` carry a challenge in this deployment?
     */
    public function protects(string $form): bool
    {
        if (! $this->verifier->isEnabled()) {
            return false;
        }

        $forms = config('integrations.captcha.forms', []);

        return is_array($forms) && in_array($form, $forms, true);
    }

    /**
     * Judge a submission, applying the fail-open policy.
     */
    public function allows(?string $token, ?string $ip, string $form): bool
    {
        $result = $this->verifier->verify($token, $ip);

        if ($result->verified) {
            return true;
        }

        if (! $result->reachable && $this->failsOpen()) {
            Log::warning('Allowed a submission the captcha could not judge.', [
                'form' => $form,
                'reason' => $result->reason(),
            ]);

            return true;
        }

        $this->logRejection($result, $form, $ip);

        return false;
    }

    /**
     * The public site key the browser needs, or null when nothing is
     * challenged. Shared with every Inertia page so any form can render a
     * widget without its controller having to pass the key down.
     */
    public function siteKey(): ?string
    {
        return $this->verifier->isEnabled() ? $this->verifier->siteKey() : null;
    }

    /**
     * The forms that are challenged, for the front end to consult before
     * rendering a widget.
     *
     * @return array<int, string>
     */
    public function protectedForms(): array
    {
        if (! $this->verifier->isEnabled()) {
            return [];
        }

        $forms = config('integrations.captcha.forms', []);

        return is_array($forms) ? array_values(array_map(strval(...), $forms)) : [];
    }

    private function failsOpen(): bool
    {
        return (bool) config('integrations.captcha.fail_open', true);
    }

    private function logRejection(CaptchaResult $result, string $form, ?string $ip): void
    {
        Log::info('Rejected a submission that failed the captcha.', [
            'form' => $form,
            'ip' => $ip,
            'reason' => $result->reason(),
        ]);
    }
}
