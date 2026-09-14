<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Orders\Database\Factories\TermsAcceptanceFactory;
use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Models\SellerPolicy;
use App\Support\Database\AppendOnly;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one buyer agreed to, for one seller's order, at one moment.
 *
 * `policies` holds the id and the VERSION of every document that was on
 * screen. The version is the load-bearing part: seller policies are published
 * as new versions rather than edited, so recording the number means the exact
 * text a buyer accepted can still be read out of seller_policies months later
 * when the seller has published three more.
 *
 * The platform's minimum refund rule is copied in as text instead, because it
 * lives in the settings table and has no version history at all.
 *
 * @property int $id
 * @property int $order_id
 * @property int $order_group_id
 * @property int $user_id
 * @property int $seller_id
 * @property array<int, array{policy_id: int, type: string, version: int, effective_from: string|null}> $policies
 * @property string|null $platform_terms_version
 * @property int $minimum_refund_days
 * @property string $minimum_refund_statement
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $accepted_at
 * @property Carbon|null $created_at
 * @property-read Order $order
 * @property-read User $buyer
 * @property-read Seller $seller
 */
class TermsAcceptance extends Model
{
    /** @use HasFactory<TermsAcceptanceFactory> */
    use AppendOnly, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'policies' => 'array',
            'minimum_refund_days' => 'integer',
            'accepted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<OrderGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(OrderGroup::class, 'order_group_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * The version number the buyer accepted for one kind of policy.
     */
    public function versionOf(PolicyType $type): ?int
    {
        foreach ($this->policies as $policy) {
            if ($policy['type'] === $type->value) {
                return (int) $policy['version'];
            }
        }

        return null;
    }

    /**
     * The policy rows the buyer actually read, however many versions the
     * seller has published since.
     *
     * Fetched by primary key rather than by (seller, type, current), which is
     * the whole reason the ids were recorded: `is_current` has almost
     * certainly moved on.
     *
     * @return Collection<int, SellerPolicy>
     */
    public function acceptedPolicies(): Collection
    {
        $ids = array_map(static fn (array $policy): int => (int) $policy['policy_id'], $this->policies);

        return SellerPolicy::query()->whereKey($ids)->get();
    }
}
