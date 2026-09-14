<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import RatingCard from '@/components/ratings/RatingCard.vue';
import type { Rating } from '@/types';

/**
 * A page of reviews.
 *
 * Paginated on the server with its own page name (`reviews`), so paging
 * through a shop's reviews does not reset the listing grid on the same page.
 */
withDefaults(
    defineProps<{
        reviews: {
            data: Rating[];
            links: { url: string | null; label: string; active: boolean }[];
        };
        reportReasons?: { value: string; label: string }[];
    }>(),
    { reportReasons: () => [] },
);
</script>

<template>
    <div>
        <p
            v-if="reviews.data.length === 0"
            class="text-muted-foreground py-6 text-sm"
        >
            Nobody has written a review yet.
        </p>

        <RatingCard
            v-for="rating in reviews.data"
            :key="rating.id"
            :rating="rating"
            :report-reasons="reportReasons"
        />

        <nav
            v-if="reviews.links.length > 3"
            class="flex flex-wrap gap-1 pt-4"
            aria-label="Review pages"
        >
            <template v-for="link in reviews.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md border px-3 py-1 text-sm"
                    :class="
                        link.active ? 'bg-primary text-primary-foreground' : ''
                    "
                    v-html="link.label"
                />
                <span
                    v-else
                    class="text-muted-foreground rounded-md px-3 py-1 text-sm"
                    v-html="link.label"
                />
            </template>
        </nav>
    </div>
</template>
