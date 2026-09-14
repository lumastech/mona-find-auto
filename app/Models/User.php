<?php

namespace App\Models;

use App\Modules\Identity\Concerns\IsMonaFindAccount;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Sellers\Concerns\SellsOnMonaFind;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Models\SellerRegistrationDraft;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property string $name Kept in step with first_name and last_name on save.
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $phone E.164, e.g. "+260977123456".
 * @property string|null $phone_network
 * @property Carbon|null $phone_verified_at
 * @property string $password
 * @property AccountStatus $status
 * @property string|null $status_reason
 * @property Carbon|null $status_changed_at
 * @property int|null $province_id
 * @property int|null $city_id
 * @property string|null $street
 * @property string|null $plot_number
 * @property Carbon|null $last_seen_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Seller|null $seller
 * @property-read SellerRegistrationDraft|null $sellerRegistrationDraft
 */
#[Fillable([
    'first_name',
    'last_name',
    'email',
    'phone',
    'phone_network',
    'password',
    'province_id',
    'city_id',
    'street',
    'plot_number',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, IsMonaFindAccount, Notifiable, PasskeyAuthenticatable, SellsOnMonaFind, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...$this->identityCasts(),
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
