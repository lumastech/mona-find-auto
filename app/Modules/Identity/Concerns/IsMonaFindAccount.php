<?php

declare(strict_types=1);

namespace App\Modules\Identity\Concerns;

use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Models\City;
use App\Modules\Identity\Models\PhoneVerification;
use App\Modules\Identity\Models\Province;
use App\Modules\Identity\Models\SocialAccount;
use App\Modules\Identity\Models\UserAddress;
use App\Modules\Identity\Support\PhoneNumberCast;
use App\Modules\Identity\Support\ZambianPhone;
use App\Support\Roles\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The identity half of App\Models\User.
 *
 * User itself stays in App\Models because the framework, notifications and
 * the audit trail all reach for it; everything about *being a MonaFind
 * account* — status, phone, addresses, linked logins — belongs to the
 * Identity module and lives here.
 *
 * @phpstan-require-extends Model
 */
trait IsMonaFindAccount
{
    /**
     * Keep the display name in step with the two name fields.
     *
     * `name` stays a real column so Laravel's notifications, the audit trail
     * and any `orderBy('name')` keep working without knowing about first and
     * last names. A name set explicitly always wins — seeders and fixtures
     * that write one should not have it silently rewritten.
     */
    public static function bootIsMonaFindAccount(): void
    {
        static::saving(function (self $user): void {
            if ($user->isDirty('name') && filled($user->name)) {
                return;
            }

            $assembled = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

            if ($assembled !== '') {
                $user->name = $assembled;
            }
        });

        /*
         * The network is derived from the number rather than stored
         * independently, so the two can never drift — a payout routed to the
         * wrong operator is a real loss, not a display bug.
         */
        static::saving(function (self $user): void {
            if ($user->isDirty('phone')) {
                $user->phone_network = ZambianPhone::tryParse($user->phone)?->network->value;
            }
        });
    }

    /**
     * The casts every MonaFind account needs, merged by User::casts().
     *
     * @return array<string, string>
     */
    protected function identityCasts(): array
    {
        return [
            'phone' => PhoneNumberCast::class,
            'phone_verified_at' => 'datetime',
            'status' => AccountStatus::class,
            'status_changed_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<UserAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class)->orderByDesc('is_default')->orderBy('label');
    }

    /**
     * @return HasOne<UserAddress, $this>
     */
    public function defaultAddress(): HasOne
    {
        return $this->hasOne(UserAddress::class)->where('is_default', true);
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * @return HasMany<PhoneVerification, $this>
     */
    public function phoneVerifications(): HasMany
    {
        return $this->hasMany(PhoneVerification::class);
    }

    /**
     * @return BelongsTo<Province, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === AccountStatus::Suspended;
    }

    public function isClosed(): bool
    {
        return $this->status === AccountStatus::Closed;
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /**
     * The account's number as a parsed value object, when it holds a valid
     * Zambian mobile number.
     */
    public function phoneNumber(): ?ZambianPhone
    {
        return ZambianPhone::tryParse($this->phone);
    }

    /**
     * Whether this account must pass a TOTP challenge to log in.
     *
     * Staff can move money and change other people's accounts, so a password
     * alone is never enough for them.
     */
    public function requiresTwoFactor(): bool
    {
        return $this->hasAnyRole(Role::requiringTwoFactor());
    }

    /**
     * Whether two-factor authentication is set up and confirmed.
     */
    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Staff who have not yet enrolled in two-factor authentication. They can
     * reach nothing but the enrolment screen until they do.
     */
    public function mustEnrolInTwoFactor(): bool
    {
        return $this->requiresTwoFactor() && ! $this->hasConfirmedTwoFactor();
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeWithStatus(Builder $query, AccountStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * Free-text search over the fields staff actually type into the box.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        /* A staff member pasting "0977 123 456" should find +260977123456. */
        $phone = ZambianPhone::tryParse($term)?->e164();

        $query->where(function (Builder $query) use ($term, $phone): void {
            $query->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', '%'.($phone ?? $term).'%');
        });
    }
}
