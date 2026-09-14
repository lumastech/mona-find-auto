<script setup lang="ts">
import { BadgeCheck, CornerDownRight } from '@lucide/vue';
import StarRating from '@/components/ratings/StarRating.vue';
import ReportRatingDialog from '@/components/ratings/ReportRatingDialog.vue';
import { Badge } from '@/components/ui/badge';
import type { Rating } from '@/types';

/**
 * One review, with its photographs and the seller's single reply.
 *
 * The reply is indented under the review rather than shown beside it, because
 * it is an answer to that review and nothing else — a seller gets one, and
 * the layout should make that read as a conversation that has finished.
 */
withDefaults(
    defineProps<{
        rating: Rating;
        reportReasons?: { value: string; label: string }[];
    }>(),
    { reportReasons: () => [] },
);
</script>

<template>
    <article class="space-y-3 border-b py-5 last:border-b-0">
        <header class="flex flex-wrap items-center gap-2">
            <StarRating :model-value="rating.stars" size="sm" />
            <span class="text-sm font-medium">{{ rating.author }}</span>
            <Badge v-if="rating.verified_purchase" variant="secondary">
                <BadgeCheck aria-hidden="true" />
                Verified purchase
            </Badge>
            <time
                v-if="rating.created_at"
                class="text-muted-foreground ml-auto text-xs"
                :datetime="rating.created_at"
            >
                {{ new Date(rating.created_at).toLocaleDateString() }}
            </time>
        </header>

        <p
            v-if="rating.body"
            class="text-sm leading-relaxed whitespace-pre-line"
        >
            {{ rating.body }}
        </p>

        <ul v-if="rating.photos.length > 0" class="flex flex-wrap gap-2">
            <li v-for="photo in rating.photos" :key="photo.id">
                <a :href="photo.url" target="_blank" rel="noopener">
                    <img
                        :src="photo.thumb_url"
                        alt="Photo attached to this review"
                        loading="lazy"
                        class="size-20 rounded-md object-cover"
                    />
                </a>
            </li>
        </ul>

        <div
            v-if="rating.reply"
            class="bg-muted/50 flex gap-2 rounded-md p-3 text-sm"
        >
            <CornerDownRight
                class="text-muted-foreground mt-0.5 size-4 shrink-0"
                aria-hidden="true"
            />
            <div class="space-y-1">
                <p class="font-medium">Reply from the seller</p>
                <p class="whitespace-pre-line">{{ rating.reply.body }}</p>
            </div>
        </div>

        <footer v-if="rating.can.report && reportReasons.length > 0">
            <ReportRatingDialog :rating="rating" :reasons="reportReasons" />
        </footer>
    </article>
</template>
