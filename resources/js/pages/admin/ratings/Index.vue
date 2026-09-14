<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { EyeOff, RotateCcw } from '@lucide/vue';
import { ref } from 'vue';
import RatingCard from '@/components/ratings/RatingCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import adminRatings from '@/routes/admin/ratings';
import type { Rating } from '@/types';

/**
 * The review queue.
 *
 * It opens on what needs deciding — held by the automatic screen, or reported
 * by somebody — oldest first. The other statuses are a filter rather than the
 * default view, because a queue that opens on the archive is a queue nobody
 * works.
 *
 * Both buttons demand a reason before they will submit. It goes into the
 * audit trail and into what the seller is told, and a moderator who has to
 * write one hides fewer reviews they merely disagree with.
 */
defineProps<{
    ratings: {
        data: Rating[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: { status: string | null };
    statuses: { value: string; label: string }[];
}>();

const acting = ref<number | null>(null);

const form = useForm({ reason: '' });

const act = (rating: Rating, action: 'hide' | 'restore'): void => {
    const route =
        action === 'hide'
            ? adminRatings.hide(rating.id).url
            : adminRatings.restore(rating.id).url;

    form.post(route, {
        preserveScroll: true,
        onSuccess: () => {
            acting.value = null;
            form.reset();
        },
    });
};
</script>

<template>
    <Head title="Review queue" />

    <div class="space-y-6 p-4">
        <Card>
            <CardHeader>
                <CardTitle>Review queue</CardTitle>
                <CardDescription>
                    Reviews the automatic screen held back, and reviews somebody
                    reported. Contact details are redacted before a review ever
                    reaches this page.
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-wrap gap-2">
                <Link
                    :href="adminRatings.index().url"
                    class="rounded-md border px-3 py-1 text-sm"
                    :class="
                        filters.status === null
                            ? 'bg-primary text-primary-foreground'
                            : ''
                    "
                >
                    Needs a decision
                </Link>
                <Link
                    v-for="status in statuses"
                    :key="status.value"
                    :href="
                        adminRatings.index({ query: { status: status.value } })
                            .url
                    "
                    class="rounded-md border px-3 py-1 text-sm"
                    :class="
                        filters.status === status.value
                            ? 'bg-primary text-primary-foreground'
                            : ''
                    "
                >
                    {{ status.label }}
                </Link>
            </CardContent>
        </Card>

        <p
            v-if="ratings.data.length === 0"
            class="text-muted-foreground text-sm"
        >
            Nothing waiting. Reviews that screen clean publish on their own.
        </p>

        <Card v-for="rating in ratings.data" :key="rating.id">
            <CardHeader>
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :variant="rating.status_variant ?? 'outline'">
                        {{ rating.status_label }}
                    </Badge>
                    <Badge v-if="rating.was_redacted" variant="outline">
                        Contact details removed
                    </Badge>
                    <Badge
                        v-for="flag in rating.screen_flags ?? []"
                        :key="flag.value"
                        variant="outline"
                    >
                        {{ flag.label }}
                    </Badge>
                    <span class="text-muted-foreground ml-auto text-sm">
                        About {{ rating.subject }} · by
                        {{ rating.submitted_by }}
                    </span>
                </div>
            </CardHeader>

            <CardContent class="space-y-4">
                <RatingCard :rating="rating" />

                <div v-if="(rating.reports ?? []).length > 0" class="space-y-2">
                    <h3 class="text-sm font-medium">
                        Reports ({{ rating.reports_count }})
                    </h3>
                    <ul class="space-y-1 text-sm">
                        <li
                            v-for="report in rating.reports"
                            :key="report.id"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <Badge :variant="report.status_variant">
                                {{ report.status_label }}
                            </Badge>
                            <span class="font-medium">{{
                                report.reason_label
                            }}</span>
                            <span class="text-muted-foreground">
                                {{ report.details }} — {{ report.reporter }}
                            </span>
                        </li>
                    </ul>
                </div>

                <p
                    v-if="rating.moderation_reason"
                    class="text-muted-foreground text-sm"
                >
                    Last decision: {{ rating.moderation_reason }}
                    <template v-if="rating.moderated_by">
                        ({{ rating.moderated_by }})
                    </template>
                </p>

                <div v-if="acting === rating.id" class="space-y-2">
                    <Label :for="`reason-${rating.id}`">
                        Reason — this is audited and shown to the seller
                    </Label>
                    <textarea
                        :id="`reason-${rating.id}`"
                        v-model="form.reason"
                        rows="2"
                        class="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                    />
                    <div class="flex gap-2">
                        <Button
                            size="sm"
                            variant="destructive"
                            :disabled="
                                form.processing || form.reason.length < 5
                            "
                            @click="act(rating, 'hide')"
                        >
                            <EyeOff aria-hidden="true" />
                            Hide it
                        </Button>
                        <Button
                            size="sm"
                            :disabled="
                                form.processing || form.reason.length < 5
                            "
                            @click="act(rating, 'restore')"
                        >
                            <RotateCcw aria-hidden="true" />
                            Publish it
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            @click="acting = null"
                        >
                            Cancel
                        </Button>
                    </div>
                </div>

                <Button
                    v-else
                    size="sm"
                    variant="outline"
                    @click="acting = rating.id"
                >
                    Decide
                </Button>
            </CardContent>
        </Card>

        <nav
            v-if="ratings.links.length > 3"
            class="flex flex-wrap gap-1"
            aria-label="Queue pages"
        >
            <template v-for="link in ratings.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    class="rounded-md border px-3 py-1 text-sm"
                    :class="
                        link.active ? 'bg-primary text-primary-foreground' : ''
                    "
                    v-html="link.label"
                />
            </template>
        </nav>
    </div>
</template>
