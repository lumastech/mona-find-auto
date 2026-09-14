<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import StarRating from '@/components/ratings/StarRating.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import type { RatingPrompt } from '@/types';

/**
 * "This order is finished — how did it go?"
 *
 * Shown only when the server says this person still has a rating to leave, so
 * the card and the endpoint behind it agree by construction rather than by a
 * matching `if` on each side.
 *
 * Private ratings say so, plainly and before the person types. A seller
 * writing about a buyer should know who reads it, and a buyer should not be
 * surprised later to find their review was public.
 */
const props = defineProps<{
    prompt: RatingPrompt;
    /** Where to post: the storefront route for a buyer, the seller one for a shop. */
    action: string;
    /** Photographs are worth collecting on a public review, not on a private one. */
    allowPhotos?: boolean;
}>();

/*
 * `rating` is not a field on the form — it is where the server puts "you
 * cannot rate this", which arrives as an error rather than as a field
 * validation failure. Declaring it keeps that message renderable.
 */
const form = useForm<{
    direction: string;
    stars: number;
    body: string;
    photos: File[];
    rating: string;
}>({
    direction: props.prompt.direction,
    stars: 0,
    body: '',
    photos: [],
    rating: '',
});

const onFiles = (event: Event): void => {
    form.photos = Array.from((event.target as HTMLInputElement).files ?? []);
};

const submit = (): void => {
    form.post(props.action, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{ prompt.heading }}</CardTitle>
            <CardDescription v-if="prompt.is_public">
                Your review appears on the shop's page. Contact details are
                removed automatically.
            </CardDescription>
            <CardDescription v-else class="flex items-center gap-1.5">
                <Lock class="size-3.5" aria-hidden="true" />
                Private — only other sellers and MonaFind staff see this.
            </CardDescription>
        </CardHeader>

        <CardContent>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label>Your rating</Label>
                    <StarRating
                        v-model="form.stars"
                        interactive
                        size="lg"
                        :label="`Your rating of ${prompt.ratee_name}`"
                    />
                    <InputError :message="form.errors.stars" />
                </div>

                <div class="space-y-2">
                    <Label for="rating-body">
                        Anything you want to add? (optional)
                    </Label>
                    <textarea
                        id="rating-body"
                        v-model="form.body"
                        rows="4"
                        maxlength="2000"
                        class="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                        :placeholder="
                            prompt.is_public
                                ? 'Was the part as described? How was the service?'
                                : 'Did they collect and pay as agreed?'
                        "
                    />
                    <InputError :message="form.errors.body" />
                </div>

                <div v-if="allowPhotos" class="space-y-2">
                    <Label for="rating-photos">Photos (optional)</Label>
                    <input
                        id="rating-photos"
                        type="file"
                        multiple
                        accept="image/*"
                        class="block w-full text-sm"
                        @change="onFiles"
                    />
                    <InputError :message="form.errors.photos" />
                </div>

                <InputError :message="form.errors.rating" />

                <Button
                    type="submit"
                    :disabled="form.processing || form.stars < 1"
                >
                    Leave rating
                </Button>
            </form>
        </CardContent>
    </Card>
</template>
