<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Enums\SocialProvider;
use App\Modules\Identity\Events\AccountRegistered;
use App\Modules\Identity\Models\SocialAccount;
use App\Support\Roles\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Turns a Google or Facebook identity into a MonaFind account.
 *
 * Three cases, in order:
 *
 * 1. the identity is already linked — sign that account in;
 * 2. the provider's verified email matches an account — link and sign in;
 * 3. neither — create a Pending account and send the person to finish it.
 *
 * A social login never produces a fully active account on its own: MonaFind
 * needs a verified Zambian phone number before anyone can buy or sell, so a
 * new social account lands in Pending exactly like a form registration does.
 */
class SocialAuthService
{
    /**
     * Find or create the account behind an OAuth identity, and keep the
     * stored profile in step with the provider.
     */
    public function resolve(SocialProvider $provider, SocialiteUser $oauthUser): User
    {
        $existing = SocialAccount::query()
            ->where('provider', $provider->value)
            ->where('provider_id', $oauthUser->getId())
            ->first();

        if ($existing !== null) {
            $this->refreshLink($existing, $oauthUser);

            return $existing->user;
        }

        $email = $oauthUser->getEmail();

        $user = $email === null
            ? null
            : User::query()->where('email', $email)->first();

        if ($user !== null) {
            $this->link($user, $provider, $oauthUser, alreadyRegistered: true);

            return $user;
        }

        return $this->register($provider, $oauthUser);
    }

    /**
     * Attach a provider identity to an account that already exists.
     */
    public function link(User $user, SocialProvider $provider, SocialiteUser $oauthUser, bool $alreadyRegistered = true): SocialAccount
    {
        $account = SocialAccount::query()->updateOrCreate(
            ['provider' => $provider->value, 'provider_id' => $oauthUser->getId()],
            [
                'user_id' => $user->getKey(),
                ...$this->profileAttributes($oauthUser),
            ],
        );

        if ($alreadyRegistered) {
            audit($user, 'user.social_linked', $user, null, [
                'provider' => $provider->value,
                'email' => $oauthUser->getEmail(),
            ]);
        }

        return $account;
    }

    /**
     * Create an account from an OAuth profile.
     *
     * There is no phone number and no address yet — the person is sent to
     * finish their profile, and only then does the account become active.
     */
    private function register(SocialProvider $provider, SocialiteUser $oauthUser): User
    {
        return DB::transaction(function () use ($provider, $oauthUser): User {
            [$firstName, $lastName] = $this->splitName($oauthUser);

            $user = User::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $oauthUser->getEmail() ?? $this->placeholderEmail($provider, $oauthUser),
                /* No password: this account signs in through the provider until it sets one. */
                'password' => Str::password(32),
            ]);

            $user->forceFill([
                'status' => AccountStatus::Pending,
                'status_changed_at' => now(),
                /* The provider already proved the address, so we do not ask again. */
                'email_verified_at' => $oauthUser->getEmail() === null ? null : now(),
            ])->save();

            $user->assignRole(Role::Buyer->value);

            $this->link($user, $provider, $oauthUser, alreadyRegistered: false);

            audit($user, 'user.registered', $user, null, [
                'email' => $user->email,
                'via' => $provider->value,
            ]);

            AccountRegistered::dispatch($user, $provider->value);

            return $user;
        });
    }

    private function refreshLink(SocialAccount $account, SocialiteUser $oauthUser): void
    {
        $account->update($this->profileAttributes($oauthUser));
    }

    /**
     * @return array<string, mixed>
     */
    private function profileAttributes(SocialiteUser $oauthUser): array
    {
        $expiresIn = $oauthUser->expiresIn ?? null;

        return [
            'email' => $oauthUser->getEmail(),
            'nickname' => $oauthUser->getNickname() ?? $oauthUser->getName(),
            'avatar_url' => $oauthUser->getAvatar(),
            'access_token' => $oauthUser->token ?? null,
            'refresh_token' => $oauthUser->refreshToken ?? null,
            'token_expires_at' => is_numeric($expiresIn) ? now()->addSeconds((int) $expiresIn) : null,
        ];
    }

    /**
     * Providers hand back one display name; MonaFind stores two.
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(SocialiteUser $oauthUser): array
    {
        $name = trim((string) ($oauthUser->getName() ?? $oauthUser->getNickname() ?? ''));

        if ($name === '') {
            return ['MonaFind', 'Member'];
        }

        $parts = preg_split('/\s+/', $name) ?: [$name];
        $first = array_shift($parts);

        return [$first, $parts === [] ? $first : implode(' ', $parts)];
    }

    /**
     * Facebook will not always release an email address. The account still
     * needs a unique one, and the person can change it while completing
     * their profile.
     */
    private function placeholderEmail(SocialProvider $provider, SocialiteUser $oauthUser): string
    {
        return sprintf('%s-%s@social.monafindauto.invalid', $provider->value, $oauthUser->getId());
    }
}
