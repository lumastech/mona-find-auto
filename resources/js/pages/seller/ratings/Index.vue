<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ShieldAlert, Star } from '@lucide/vue';
import { ref } from 'vue';
import RatingCard from '@/components/ratings/RatingCard.vue';
import RatingSummary from '@/components/ratings/RatingSummary.vue';
import ReplyForm from '@/components/ratings/ReplyForm.vue';
import StarRating from '@/components/ratings/StarRating.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type {
    BadgeVariantName,
    Rating,
    RatingSummary as Summary,
} from '@/types';

/**
 * What buyers said, and what the shop said back.
 *
 * The trust score sits at the top rather than at the bottom because it is the
 * part that costs the seller money: it feeds the search ranking, and a shop
 * whose score has slipped wants to know that before it reads the review that
 * caused it.
 *
 * Ratings the shop has left about buyers are a separate tab. They are not
 * public and this is one of only two places they can be read.
 */
defineProps<{
    received: {
        data: Rating[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    given: {
        data: Rating[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    summary: Summary;
    trust: {
        score: number;
        band: string;
        band_label: string;
        band_variant: BadgeVariantName;
        dispute_rate_percent: number;
        computed_at: string | null;
    };
}>();

const tab = ref<'received' | 'given'>('received');
</script>

<template>
    <Head title="Reviews" />

    <div class="space-y-6 p-4">
        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <Star class="size-5" aria-hidden="true" />
                    Trust score
                </CardTitle>
                <CardDescription>
                    Built from your reviews, your dispute rate and how many of
                    your paid orders finished. It is one of the things that
                    decides where your parts appear in search.
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-wrap items-center gap-6">
                <div>
                    <p class="text-3xl font-semibold tabular-nums">
                        {{ trust.score.toFixed(1)
                        }}<span class="text-muted-foreground text-base"
                            >/100</span
                        >
                    </p>
                    <Badge :variant="trust.band_variant">{{
                        trust.band_label
                    }}</Badge>
                </div>
                <div class="text-sm">
                    <p class="text-muted-foreground">Dispute rate</p>
                    <p class="tabular-nums">
                        {{ trust.dispute_rate_percent.toFixed(2) }}%
                    </p>
                </div>
                <div v-if="trust.computed_at" class="text-sm">
                    <p class="text-muted-foreground">Last worked out</p>
                    <p>{{ new Date(trust.computed_at).toLocaleString() }}</p>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardContent class="pt-6">
                <RatingSummary :summary="summary" heading="What buyers said" />
            </CardContent>
        </Card>

        <div class="flex gap-2" role="tablist">
            <Button
                :variant="tab === 'received' ? 'default' : 'outline'"
                size="sm"
                role="tab"
                :aria-selected="tab === 'received'"
                @click="tab = 'received'"
            >
                Reviews of your shop
            </Button>
            <Button
                :variant="tab === 'given' ? 'default' : 'outline'"
                size="sm"
                role="tab"
                :aria-selected="tab === 'given'"
                @click="tab = 'given'"
            >
                Your ratings of buyers
            </Button>
        </div>

        <Card v-show="tab === 'received'">
            <CardContent class="pt-6">
                <p
                    v-if="received.data.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    No reviews yet. Buyers can only review an order that
                    completed.
                </p>

                <div
                    v-for="rating in received.data"
                    :key="rating.id"
                    class="border-b py-4 last:border-b-0"
                >
                    <RatingCard :rating="rating" />
                    <ReplyForm
                        v-if="rating.can.reply"
                        :rating="rating"
                        class="mt-3"
                    />
                </div>

                <nav
                    v-if="received.links.length > 3"
                    class="flex flex-wrap gap-1 pt-4"
                    aria-label="Review pages"
                >
                    <template
                        v-for="link in received.links"
                        :key="`r-${link.label}`"
                    >
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            class="rounded-md border px-3 py-1 text-sm"
                            :class="
                                link.active
                                    ? 'bg-primary text-primary-foreground'
                                    : ''
                            "
                            v-html="link.label"
                        />
                    </template>
                </nav>
            </CardContent>
        </Card>

        <Card v-show="tab === 'given'">
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <ShieldAlert class="size-5" aria-hidden="true" />
                    Your ratings of buyers
                </CardTitle>
                <CardDescription>
                    Private. Buyers never see these — other sellers, mechanics
                    and MonaFind staff do.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <p
                    v-if="given.data.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    You have not rated any buyers yet. The option appears on
                    each completed order.
                </p>

                <article
                    v-for="rating in given.data"
                    :key="rating.id"
                    class="space-y-1 border-b py-4 last:border-b-0"
                >
                    <div class="flex items-center gap-2">
                        <StarRating :model-value="rating.stars" size="sm" />
                        <span class="text-sm font-medium">{{
                            rating.buyer
                        }}</span>
                        <time
                            v-if="rating.created_at"
                            class="text-muted-foreground ml-auto text-xs"
                            :datetime="rating.created_at"
                        >
                            {{
                                new Date(rating.created_at).toLocaleDateString()
                            }}
                        </time>
                    </div>
                    <p v-if="rating.body" class="text-sm">{{ rating.body }}</p>
                </article>
            </CardContent>
        </Card>
    </div>
</template>
