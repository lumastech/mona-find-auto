<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Models\User;
use App\Modules\Identity\Events\AccountRegistered;
use App\Modules\Identity\Http\Middleware\EnsurePhoneIsVerified;
use App\Modules\Identity\Http\Responses\RegisterResponse;
use App\Modules\Identity\Listeners\SendWelcomeNotification;
use App\Modules\Identity\Models\City;
use App\Modules\Identity\Models\Province;
use App\Modules\Identity\Models\UserAddress;
use App\Modules\Identity\Policies\UserAddressPolicy;
use App\Modules\Identity\Policies\UserPolicy;
use App\Modules\Identity\Privacy\IdentityPersonalData;
use App\Modules\Identity\Support\ZambianPhone;
use App\Modules\Privacy\Support\PersonalDataRegistry;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Reference\ReferenceList;
use App\Support\Reference\ReferenceRegistry;
use App\Support\Roles\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Fortify;

/**
 * Identity module — accounts, authentication, roles and permissions.
 *
 * This is the module every other one leans on: the gates defined here are
 * what Catalog, Orders and Finance check, and the account statuses defined
 * here are what decide whether anybody can do anything at all.
 */
class IdentityServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
    }

    protected function bootModule(): void
    {
        $this->registerPersonalData();
        $this->registerReferenceLists();

        $this->registerPolicies();
        $this->registerGates();
        $this->registerMiddlewareAliases();
        $this->configureAuthentication();

        /* One welcome, whether the account arrived by form, API or Google. */
        Event::listen(AccountRegistered::class, SendWelcomeNotification::class);
    }

    private function registerPolicies(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(UserAddress::class, UserAddressPolicy::class);
    }

    /**
     * The gates the rest of the platform checks.
     *
     * Later modules ask `Gate::allows('staff')` rather than repeating a role
     * list, so widening what counts as staff is a change in one place.
     */
    private function registerGates(): void
    {
        Gate::define('staff', fn (User $user): bool => $user->hasAnyRole(Role::staffConsole()));

        Gate::define('admin-only', fn (User $user): bool => $user->hasRole(Role::PlatformAdmin->value));

        Gate::define('finance', fn (User $user): bool => $user->hasAnyRole([
            Role::Finance->value,
            Role::PlatformAdmin->value,
        ]));

        Gate::define('moderate', fn (User $user): bool => $user->hasAnyRole([
            Role::Moderator->value,
            Role::PlatformAdmin->value,
        ]));

        Gate::define('sell', fn (User $user): bool => $user->hasAnyRole(Role::sellerPortal()));
    }

    private function registerMiddlewareAliases(): void
    {
        Route::aliasMiddleware('phone.verified', EnsurePhoneIsVerified::class);
    }

    /**
     * Sign in with an email address or a phone number.
     *
     * Most Zambian buyers know their number better than their email address,
     * so both are accepted in the same field. A suspended or closed account
     * is refused here as well as in middleware — failing at the login screen
     * is what lets us show the recorded reason.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $identifier = trim((string) $request->input(Fortify::username()));

            $user = $this->findByIdentifier($identifier);

            if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            if ($user->status->forcesLogout()) {
                throw ValidationException::withMessages([
                    Fortify::username() => $user->status->blockedMessage($user->status_reason),
                ]);
            }

            $user->forceFill(['last_seen_at' => now()])->saveQuietly();

            return $user;
        });
    }

    /**
     * Resolve whatever was typed into the login field.
     *
     * An "@" settles it: nothing else is an email address, and no phone
     * number contains one.
     */
    private function findByIdentifier(string $identifier): ?User
    {
        if ($identifier === '') {
            return null;
        }

        $phone = str_contains($identifier, '@')
            ? null
            : ZambianPhone::tryParse($identifier)?->e164();

        return User::query()
            ->when(
                $phone !== null,
                fn ($query) => $query->where('phone', $phone),
                fn ($query) => $query->where('email', Str::lower($identifier)),
            )
            ->first();
    }

    /**
     * Zambia's provinces and towns.
     *
     * These are the only reference lists the platform shares with every other
     * module — a seller, a mechanic, a buyer and a delivery address all point
     * at the same town row — which is exactly why merging one has to go
     * through the registry rather than through an UPDATE somebody wrote by
     * hand. Provinces are fixed by geography and carry no retirement flag;
     * the only sensible correction to one is a merge.
     */
    private function registerReferenceLists(): void
    {
        $references = $this->app->make(ReferenceRegistry::class);

        $references->register(new ReferenceList(
            key: 'provinces',
            label: 'Provinces',
            singular: 'province',
            model: Province::class,
            owner: 'Identity',
            retirable: false,
            note: 'Zambia has ten. New rows are almost always a mistake.',
        ));

        $references->register(new ReferenceList(
            key: 'cities',
            label: 'Towns and cities',
            singular: 'town',
            model: City::class,
            owner: 'Identity',
            retirable: false,
            parentKey: 'provinces',
            parentColumn: 'province_id',
            note: 'Where a shop is, where a part ships from, and what "nearest first" sorts on.',
        ));

        $references->link('provinces', 'cities', 'province_id');
        $references->link('provinces', 'users', 'province_id');
        $references->link('provinces', 'user_addresses', 'province_id');
        $references->link('cities', 'users', 'city_id');
        $references->link('cities', 'user_addresses', 'city_id');
    }

    /**
     * Tell Privacy what personal data this module holds.
     *
     * The module owns the answer because the module owns the tables. Privacy
     * orchestrates export and erasure; it never reads these models itself.
     */
    private function registerPersonalData(): void
    {
        $this->app->make(PersonalDataRegistry::class)->register(IdentityPersonalData::class);
    }
}
