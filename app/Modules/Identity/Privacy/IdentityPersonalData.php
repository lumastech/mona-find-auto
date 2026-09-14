<?php

declare(strict_types=1);

namespace App\Modules\Identity\Privacy;

use App\Models\User;
use App\Modules\Identity\Models\PhoneVerification;
use App\Modules\Identity\Models\SocialAccount;
use App\Modules\Identity\Models\UserAddress;
use App\Modules\Privacy\Contracts\PersonalDataSource;
use App\Modules\Privacy\Support\Anonymiser;
use App\Modules\Privacy\Support\PersonalDataSection;
use Illuminate\Support\Facades\DB;

/**
 * The account itself: who a person told MonaFind they are.
 *
 * The densest concentration of personal data on the platform, and the one
 * whose erasure has to be got exactly right.
 *
 * ## The users row is updated, not deleted
 *
 * Every other table here is emptied. `users` is not, and cannot be: orders,
 * ledger lines, payments and audit rows all point at it, several of those
 * with `cascadeOnDelete`, and the terms_acceptances trigger would abort the
 * statement half-way through. So the row survives holding tombstones. See
 * App\Modules\Privacy\Services\AccountEraser for the full reasoning.
 *
 * ## saveQuietly, and why
 *
 * The write goes through `forceFill(...)->saveQuietly()`. A normal save would
 * fire the model events that keep `name` in step with the first and last
 * name, re-derive `phone_network` from a phone column that now reads
 * "erased-91", and dispatch whatever else listens to a user being updated —
 * including, potentially, a Scout index write carrying the data we are in the
 * middle of removing.
 *
 * ## Two-factor secrets go too
 *
 * They are credentials for an account that no longer has an owner. Leaving
 * them would mean an erased account still holding a shared secret.
 */
class IdentityPersonalData implements PersonalDataSource
{
    public function key(): string
    {
        return 'account';
    }

    /**
     * @return array<int, PersonalDataSection>
     */
    public function export(User $user): array
    {
        $user->loadMissing(['province', 'city']);

        return [
            PersonalDataSection::single('Your account', [
                'Account number' => $user->getKey(),
                'First name' => $user->first_name,
                'Last name' => $user->last_name,
                'Email address' => $user->email,
                'Email verified' => $user->email_verified_at?->toDateTimeString(),
                'Phone number' => $user->phone,
                'Mobile network' => $user->phone_network,
                'Phone verified' => $user->phone_verified_at?->toDateTimeString(),
                'Account status' => $user->status->label(),
                'Province' => $user->province?->name,
                'City' => $user->city?->name,
                'Street' => $user->street,
                'Plot number' => $user->plot_number,
                'Registered on' => $user->created_at?->toDateTimeString(),
                'Last seen' => $user->last_seen_at?->toDateTimeString(),
            ], 'The details you gave us when you registered, and have edited since.'),

            PersonalDataSection::make(
                'Your roles',
                $user->roles->pluck('name')->map(static fn (string $role): array => [
                    'Role' => $role,
                ])->all(),
                'What you are allowed to do on MonaFind.',
            ),

            PersonalDataSection::make(
                'Your delivery addresses',
                UserAddress::query()
                    ->where('user_id', $user->getKey())
                    ->with(['province:id,name', 'city:id,name'])
                    ->get()
                    ->map(static fn (UserAddress $address): array => [
                        'Label' => $address->label,
                        'Recipient' => $address->recipient_name,
                        'Recipient phone' => $address->recipient_phone,
                        'Province' => $address->province->name,
                        'City' => $address->city->name,
                        'Street' => $address->street,
                        'Plot number' => $address->plot_number,
                        'Directions' => $address->directions,
                        'Default' => $address->is_default,
                        'Added on' => $address->created_at?->toDateTimeString(),
                    ])->all(),
                'Where you have asked sellers and couriers to deliver.',
            ),

            PersonalDataSection::make(
                'Linked sign-in accounts',
                SocialAccount::query()
                    ->where('user_id', $user->getKey())
                    ->get()
                    ->map(static fn (SocialAccount $account): array => [
                        'Provider' => $account->provider,
                        'Email at provider' => $account->email,
                        'Nickname at provider' => $account->nickname,
                        'Linked on' => $account->created_at?->toDateTimeString(),
                    ])->all(),
                'Google or Facebook accounts you have used to sign in. We never store a password for these.',
            ),

            PersonalDataSection::make(
                'Phone verification history',
                PhoneVerification::query()
                    ->where('user_id', $user->getKey())
                    ->latest('id')
                    ->limit(50)
                    ->get()
                    ->map(static fn (PhoneVerification $verification): array => [
                        'Phone number' => $verification->phone,
                        'Purpose' => $verification->purpose->value,
                        'Requested at' => $verification->created_at?->toDateTimeString(),
                        'Used at' => $verification->consumed_at?->toDateTimeString(),
                        'Requested from' => $verification->request_ip,
                    ])->all(),
                'The most recent 50 codes we sent you. The codes themselves are stored hashed and cannot be shown.',
            ),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function erase(User $user, Anonymiser $anonymiser): array
    {
        $tally = [];

        $tally['user_addresses'] = UserAddress::query()->where('user_id', $user->getKey())->delete();
        $tally['social_accounts'] = SocialAccount::query()->where('user_id', $user->getKey())->delete();
        $tally['phone_verifications'] = PhoneVerification::query()->where('user_id', $user->getKey())->delete();

        /* Every API token the mobile app was holding. */
        $tally['personal_access_tokens'] = DB::table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->delete();

        /*
         * Passkeys are public-key credentials tied to a device the person
         * still owns. They are meaningless without the account, but they name
         * the device and have no reason to outlive it.
         */
        if (DB::getSchemaBuilder()->hasTable('passkeys')) {
            $tally['passkeys'] = DB::table('passkeys')->where('user_id', $user->getKey())->delete();
        }

        /*
         * Any notification already written to the bell. The account cannot
         * read them and several carry names and order references.
         */
        $tally['notifications'] = DB::table('notifications')
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->delete();

        $tally['users'] = $this->anonymiseAccount($user, $anonymiser);

        return $tally;
    }

    /**
     * Replace every identifying column on the account row.
     *
     * Quietly, so that none of the model's own listeners re-derive a value
     * from a tombstone or push the data we are removing into the search index
     * on its way out.
     */
    private function anonymiseAccount(User $user, Anonymiser $anonymiser): int
    {
        $user->forceFill([
            'first_name' => $anonymiser->firstName(),
            'last_name' => $anonymiser->lastName(),
            'name' => $anonymiser->name(),
            'email' => $anonymiser->email(),
            'email_verified_at' => null,
            'phone' => $anonymiser->phone(),
            'phone_network' => null,
            'phone_verified_at' => null,
            /*
             * A password nobody can hold. Not null: the column is not
             * nullable, and a null password is the kind of thing an
             * authentication path might one day treat as "no check needed".
             */
            'password' => bcrypt(bin2hex(random_bytes(32))),
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'province_id' => null,
            'city_id' => null,
            'street' => null,
            'plot_number' => null,
            'status_reason' => 'Account erased at the account holder\'s request.',
            'last_seen_at' => null,
        ])->saveQuietly();

        return 1;
    }
}
