<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { TrendingDown } from '@lucide/vue';
import RatingSummary from '@/components/ratings/RatingSummary.vue';
import StarRating from '@/components/ratings/StarRating.vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import sellerRoutes from '@/routes/sellers';
import type { SellerTrustRow } from '@/types';

/**
 * Sellers somebody should be having a conversation with.
 *
 * Two independent reasons to be on this list: a trust score in the Watch or
 * Critical band, or a dispute rate above the platform threshold. A shop with
 * a handful of glowing reviews and a climbing dispute rate is exactly the
 * case a score on its own misses.
 *
 * Each row carries what the platform recommends doing. That is what makes
 * this a worklist rather than a leaderboard — a moderator opening it should
 * not have to decide the policy as well as the case.
 */
defineProps<{
    sellers: SellerTrustRow[];
    disputeThreshold: number;
}>();
</script>

<template>
    <Head title="Seller trust" />

    <div class="space-y-6 p-4">
        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <TrendingDown class="size-5" aria-hidden="true" />
                    Sellers to look at
                </CardTitle>
                <CardDescription>
                    Trust score in Watch or Critical, or a dispute rate above
                    {{ disputeThreshold }}%. Nothing here happens automatically
                    — every action is yours, with a reason.
                </CardDescription>
            </CardHeader>
        </Card>

        <p v-if="sellers.length === 0" class="text-muted-foreground text-sm">
            Nobody is below the line. Scores are rebuilt nightly.
        </p>

        <Card v-for="row in sellers" :key="row.seller.id">
            <CardHeader>
                <div class="flex flex-wrap items-center gap-2">
                    <CardTitle>
                        <Link
                            :href="sellerRoutes.show(row.seller.slug).url"
                            class="hover:underline"
                        >
                            {{ row.seller.name }}
                        </Link>
                    </CardTitle>
                    <Badge :variant="row.trust_band_variant">
                        {{ row.trust_band_label }} ·
                        {{ row.trust_score.toFixed(1) }}
                    </Badge>
                    <Badge
                        v-if="row.dispute_rate_percent > disputeThreshold"
                        variant="destructive"
                    >
                        {{ row.dispute_rate_percent.toFixed(2) }}% disputes
                    </Badge>
                    <Badge variant="outline">{{
                        row.seller.payment_mode
                    }}</Badge>
                </div>
            </CardHeader>

            <CardContent class="grid gap-6 md:grid-cols-2">
                <div class="space-y-2">
                    <div class="flex items-center gap-2">
                        <StarRating
                            :model-value="row.average_stars"
                            size="sm"
                        />
                        <span class="text-muted-foreground text-sm">
                            {{ row.ratings_count }} review{{
                                row.ratings_count === 1 ? '' : 's'
                            }}
                            · {{ row.completed_orders }} completed orders
                        </span>
                    </div>
                    <RatingSummary
                        :summary="row.breakdown"
                        heading="Breakdown"
                    />
                </div>

                <div>
                    <h3 class="text-sm font-medium">Recommended</h3>
                    <ul
                        class="text-muted-foreground mt-2 list-disc space-y-1 pl-5 text-sm"
                    >
                        <li
                            v-for="action in row.recommended_actions"
                            :key="action"
                        >
                            {{ action }}
                        </li>
                    </ul>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
