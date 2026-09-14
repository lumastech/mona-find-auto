<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Models\User;
use App\Modules\Ledger\Database\Factories\MonetisationPolicyFactory;
use App\Modules\Ledger\Enums\CommissionType;
use App\Modules\Ledger\Services\MonetisationPolicyService;
use App\Modules\Orders\Contracts\VatRateProvider;
use App\Modules\Orders\Support\MonetisationSnapshot;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named set of commercial terms a seller can be put on.
 *
 * Three components and no more: commission, an add-on fee and a referral fee.
 * VAT and the reserve percentage are absent on purpose — they are
 * platform-wide facts that live in settings, and a per-seller VAT rate would
 * be a tax fiction rather than a pricing decision.
 *
 * Editing a policy is safe and that is worth being explicit about, because it
 * looks dangerous. No order ever reads one of these rows: the terms in force
 * are turned into a MonetisationSnapshot and frozen onto the order when the
 * money arrives. Raising a commission today changes what tomorrow's orders
 * are charged and cannot reach back into a sale already settled.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property CommissionType $commission_type
 * @property string $commission_percent
 * @property Money $commission_flat_ngwee
 * @property Money $addon_fee_ngwee
 * @property string $referral_fee_percent
 * @property bool $is_default
 * @property bool $is_active
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Seller> $sellers
 * @property-read User|null $author
 *
 * @see MonetisationPolicyService
 */
class MonetisationPolicy extends Model
{
    /** @use HasFactory<MonetisationPolicyFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'commission_type' => CommissionType::class,
            'commission_flat_ngwee' => MoneyCast::class,
            'addon_fee_ngwee' => MoneyCast::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Seller, $this>
     */
    public function sellers(): HasMany
    {
        return $this->hasMany(Seller::class, 'monetisation_policy_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * The policy sellers fall back to when they have no override.
     *
     * Null on a database that has not been seeded, which the provider handles
     * by falling back to the raw settings — a platform mid-deployment must
     * still be able to price an order.
     */
    public static function default(): ?self
    {
        return self::query()->where('is_default', true)->first();
    }

    /**
     * Turn these terms into the snapshot an order carries.
     *
     * VAT and the reserve are read from settings here, at the one moment they
     * are allowed to be read: snapshot time. Every later question about this
     * order is answered from the frozen copy.
     */
    public function toSnapshot(): MonetisationSnapshot
    {
        return new MonetisationSnapshot(
            commissionType: $this->commission_type->value,
            commissionPercent: $this->commission_percent,
            commissionFlat: $this->commission_flat_ngwee,
            addonFee: $this->addon_fee_ngwee,
            referralFeePercent: $this->referral_fee_percent,
            /* Dated, and platform-wide: a per-seller VAT rate would be a tax fiction. */
            vatOnCommissionPercent: app(VatRateProvider::class)->percentAt(),
            reservePercent: (string) settings('risk.reserve_percent', '0.00'),
        );
    }

    /**
     * How this policy charges, in a line a human can read.
     */
    public function commissionDescription(): string
    {
        return $this->commission_type === CommissionType::Flat
            ? $this->commission_flat_ngwee->format().' per order'
            : $this->commission_percent.'% of the goods value';
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
