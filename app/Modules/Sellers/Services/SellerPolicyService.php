<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Services;

use App\Models\User;
use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Events\SellerPolicyPublished;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Models\SellerPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Publishing a seller policy.
 *
 * There is no update path here on purpose. A buyer accepts a specific version
 * of a policy at checkout and the order records which one; if editing changed
 * the text in place, a dispute six weeks later would be argued against wording
 * the buyer never saw. Every edit is a new version and every old version stays
 * readable forever.
 */
class SellerPolicyService
{
    /**
     * Put a new version of one policy into force.
     *
     * Republishing identical text is a no-op rather than a version bump —
     * a seller who opens the editor, changes nothing and saves has not
     * changed the deal, and a version history full of duplicates is
     * worthless when somebody has to read back through it.
     */
    public function publish(
        Seller $seller,
        PolicyType $type,
        string $body,
        ?User $author = null,
        ?Carbon $effectiveFrom = null,
    ): SellerPolicy {
        $body = trim($body);
        $current = $seller->currentPolicy($type);

        if ($current !== null && trim($current->body) === $body) {
            return $current;
        }

        $policy = DB::transaction(function () use ($seller, $type, $body, $author, $effectiveFrom, $current): SellerPolicy {
            $seller->policies()
                ->where('type', $type)
                ->where('is_current', true)
                ->update(['is_current' => false, 'updated_at' => now()]);

            return $seller->policies()->create([
                'type' => $type,
                'version' => ($current === null ? 0 : $current->version) + 1,
                'body' => $body,
                'effective_from' => $effectiveFrom ?? now(),
                'is_current' => true,
                'created_by' => $author?->getKey(),
            ]);
        });

        audit(
            $author,
            'seller.policy.published',
            $policy,
            $current === null ? null : ['version' => $current->version, 'body' => $current->body],
            ['version' => $policy->version, 'body' => $policy->body],
            null,
            ['seller_id' => $seller->getKey(), 'policy_type' => $type->value],
        );

        SellerPolicyPublished::dispatch($policy, $current);

        return $policy;
    }

    /**
     * Publish several policies at once, as the sign-up wizard does.
     *
     * @param  array<string, string|null>  $bodies  Keyed by PolicyType value.
     * @return array<int, SellerPolicy>
     */
    public function publishMany(Seller $seller, array $bodies, ?User $author = null): array
    {
        $published = [];

        foreach (PolicyType::cases() as $type) {
            $body = $bodies[$type->value] ?? null;

            if (! is_string($body) || trim($body) === '') {
                continue;
            }

            $published[] = $this->publish($seller, $type, $body, $author);
        }

        return $published;
    }

    /**
     * The current version of each policy, keyed by type.
     *
     * @return array<string, SellerPolicy>
     */
    public function currentFor(Seller $seller): array
    {
        return $seller->currentPolicies()
            ->get()
            ->keyBy(static fn (SellerPolicy $policy): string => $policy->type->value)
            ->all();
    }

    /**
     * The platform's own refund floor, shown beside whatever the seller
     * wrote. A seller cannot contract out of it, so a buyer has to be able
     * to read it in the same place they read the seller's policy.
     *
     * @return array{days: int, statement: string}
     */
    public function platformMinimumRefund(): array
    {
        $days = settings()->integer('policies.minimum_refund_days', 3);

        return [
            'days' => $days,
            'statement' => settings()->string(
                'policies.minimum_refund_statement',
                sprintf(
                    'Whatever this seller\'s policy says, MonaFind refunds a wrong or damaged item reported within %d days of you receiving it.',
                    $days,
                ),
            ),
        ];
    }
}
