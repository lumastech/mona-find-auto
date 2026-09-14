<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Services;

use App\Models\User;
use App\Modules\Ledger\Models\MonetisationPolicy;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creating, editing and assigning the terms sellers trade on.
 *
 * Every method here writes an audit row, because every one of them changes
 * what MonaFind will charge somebody. None of them can change what MonaFind
 * has already charged: orders read their own snapshot, so a policy edit
 * reaches forward only.
 *
 * The one invariant worth stating is that exactly one policy is the default.
 * It is enforced by flipping the others down inside the same transaction
 * rather than by a database constraint, because "at most one row where
 * is_default is true" is not something MySQL will express.
 */
class MonetisationPolicyService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?User $actor = null): MonetisationPolicy
    {
        return DB::transaction(function () use ($attributes, $actor): MonetisationPolicy {
            $policy = MonetisationPolicy::query()->create([
                ...$attributes,
                'slug' => $this->slugFor((string) $attributes['name']),
                'is_default' => false,
                'created_by' => $actor?->getKey(),
            ]);

            if ((bool) ($attributes['is_default'] ?? false)) {
                $this->makeDefault($policy, $actor, 'Set as the default on creation.');
            }

            audit($actor, 'monetisation_policy.created', $policy, null, $policy->toArray());

            return $policy->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(MonetisationPolicy $policy, array $attributes, ?User $actor = null, ?string $reason = null): MonetisationPolicy
    {
        return DB::transaction(function () use ($policy, $attributes, $actor, $reason): MonetisationPolicy {
            $before = $policy->toArray();

            $policy->fill([
                ...$attributes,
                /* The slug is the route key; renaming a policy must not break links to it. */
                'slug' => $policy->slug,
                'is_default' => $policy->is_default,
            ])->save();

            if ((bool) ($attributes['is_default'] ?? false) && ! $policy->is_default) {
                $this->makeDefault($policy, $actor, $reason);
            }

            audit($actor, 'monetisation_policy.updated', $policy, $before, $policy->refresh()->toArray(), $reason);

            return $policy;
        });
    }

    /**
     * Make this the policy sellers without an override fall back to.
     */
    public function makeDefault(MonetisationPolicy $policy, ?User $actor = null, ?string $reason = null): MonetisationPolicy
    {
        return DB::transaction(function () use ($policy, $actor, $reason): MonetisationPolicy {
            $previous = MonetisationPolicy::default();

            MonetisationPolicy::query()->where('is_default', true)->update(['is_default' => false]);

            $policy->forceFill(['is_default' => true, 'is_active' => true])->save();

            audit(
                $actor,
                'monetisation_policy.default_changed',
                $policy,
                ['policy' => $previous?->slug],
                ['policy' => $policy->slug],
                $reason,
            );

            return $policy;
        });
    }

    /**
     * Put a seller on a policy, or back on the platform default.
     *
     * Null means the default, which is where nearly every seller stays. The
     * audit row records both sides because "who moved this shop onto the
     * bespoke 4% and when" is the first question anybody asks about a payout
     * that looks wrong.
     */
    public function assign(Seller $seller, ?MonetisationPolicy $policy, ?User $actor = null, ?string $reason = null): Seller
    {
        $before = $seller->monetisation_policy_id;

        if ($before === $policy?->getKey()) {
            return $seller;
        }

        $seller->forceFill(['monetisation_policy_id' => $policy?->getKey()])->save();

        audit(
            $actor,
            'monetisation_policy.assigned',
            $seller,
            ['monetisation_policy_id' => $before],
            ['monetisation_policy_id' => $policy?->getKey(), 'policy' => $policy?->slug],
            $reason,
        );

        return $seller;
    }

    /**
     * Retire a policy.
     *
     * Deactivates rather than deletes, and refuses on the default. Sellers
     * already assigned keep it — pulling a shop onto different terms as a
     * side effect of tidying up a list is not something an administrator
     * should be able to do by accident. Move them first, deliberately.
     */
    public function deactivate(MonetisationPolicy $policy, ?User $actor = null, ?string $reason = null): MonetisationPolicy
    {
        if ($policy->is_default) {
            return $policy;
        }

        $policy->forceFill(['is_active' => false])->save();

        audit($actor, 'monetisation_policy.deactivated', $policy, ['is_active' => true], ['is_active' => false], $reason);

        return $policy;
    }

    public function reactivate(MonetisationPolicy $policy, ?User $actor = null, ?string $reason = null): MonetisationPolicy
    {
        $policy->forceFill(['is_active' => true])->save();

        audit($actor, 'monetisation_policy.reactivated', $policy, ['is_active' => false], ['is_active' => true], $reason);

        return $policy;
    }

    private function slugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'policy';
        $slug = $base;

        for ($suffix = 2; MonetisationPolicy::query()->where('slug', $slug)->exists(); $suffix++) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
