<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Events\AccountRegistered;
use App\Modules\Identity\Exceptions\OtpThrottled;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Identity\Support\AccountFieldRules;
use App\Modules\Privacy\Services\ConsentRecorder;
use App\Support\Captcha\CaptchaRule;
use App\Support\Roles\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Facades\Validator;

/**
 * Creates a MonaFind account.
 *
 * The web form (through Fortify) and the JSON API both come through here, so
 * there is one definition of what registering means: a Pending account with a
 * buyer role and a verification code already on its way.
 *
 * Every account starts as a buyer. Selling, mechanic and staff roles are
 * granted afterwards, by staff.
 *
 * ## Consent is collected here, not on the form
 *
 * The Data Protection Act 2021 puts the burden of proving consent on the
 * controller, and a tickbox that is validated and then thrown away proves
 * nothing. So the two required agreements are validated as `accepted` and
 * then written to the consent log with the version of each document that was
 * on screen. Marketing is recorded either way — a refusal that leaves no
 * trace cannot be told apart from a question never asked.
 *
 * The required consents are separate fields rather than one, because bundling
 * the terms and the privacy notice into a single tickbox is one of the things
 * that makes consent not freely given.
 */
class RegisterUser
{
    use AccountFieldRules, PasswordValidationRules;

    public function __construct(
        private readonly OtpService $otp,
        private readonly ConsentRecorder $consents,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(array $input, string $via = 'web', ?string $requestIp = null): User
    {
        $input = $this->normalisePhone($input);

        $validated = Validator::make($input, [
            ...$this->identityRules(),
            ...$this->addressRules(),
            'password' => $this->passwordRules(),
            /*
             * Proof of a person. A no-op when this deployment's captcha
             * driver challenges nobody — see App\Support\Captcha\CaptchaRule.
             */
            'captcha_token' => CaptchaRule::for('register'),
            'accept_terms' => ['accepted'],
            'accept_privacy' => ['accepted'],
            'accept_marketing' => ['nullable', 'boolean'],
        ], [
            'accept_terms.accepted' => 'Please accept the terms of service to continue.',
            'accept_privacy.accepted' => 'Please confirm you have read the privacy notice to continue.',
        ])->validate();

        $marketing = (bool) ($validated['accept_marketing'] ?? false);

        /*
         * The consent columns are not account columns, so they are dropped
         * before the model sees them. `User::create` is guarded by a fillable
         * list and would ignore them anyway; removing them here says so.
         */
        unset($validated['captcha_token'], $validated['accept_terms'], $validated['accept_privacy'], $validated['accept_marketing']);

        $user = DB::transaction(function () use ($validated, $marketing): User {
            $user = User::create($validated);

            /* Not Active until the phone number is proven; see PhoneVerificationController. */
            $user->forceFill([
                'status' => AccountStatus::Pending,
                'status_changed_at' => now(),
            ])->save();

            $user->assignRole(Role::Buyer->value);

            /*
             * Inside the transaction: an account that exists without a
             * record of what its holder agreed to is exactly the state the
             * Act does not permit, so the two commit together or not at all.
             */
            $this->consents->recordRegistration($user, $marketing, RequestFacade::instance());

            return $user;
        });

        $this->sendVerificationCode($user, $requestIp);

        audit($user, 'user.registered', $user, null, [
            'email' => $user->email,
            'phone' => $user->phone,
            'via' => $via,
        ]);

        AccountRegistered::dispatch($user, $via);

        return $user;
    }

    /**
     * Start phone verification. A throttle here is not the registration's
     * problem — the account exists and the person can ask for another code
     * from the verification screen.
     */
    private function sendVerificationCode(User $user, ?string $requestIp): void
    {
        $phone = $user->phone;

        if ($phone === null) {
            return;
        }

        try {
            $this->otp->issue($phone, OtpPurpose::PhoneVerification, $user, $requestIp);
        } catch (OtpThrottled) {
            // A code is already in flight for this number; that one still works.
        }
    }
}
