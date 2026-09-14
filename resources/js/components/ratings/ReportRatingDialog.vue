<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Flag } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import ratings from '@/routes/ratings';
import type { Rating } from '@/types';

/**
 * Objecting to a review.
 *
 * The wording is deliberate about what this does and does not do: a report is
 * read by a moderator, and a seller who simply disagrees with a review does
 * not get it removed by pressing this. Saying so here saves the queue a great
 * many reports that were only ever going to be dismissed.
 */
const props = defineProps<{
    rating: Rating;
    reasons: { value: string; label: string }[];
}>();

const open = ref(false);

const form = useForm({
    reason: props.reasons[0]?.value ?? 'other',
    details: '',
});

const submit = (): void => {
    form.post(ratings.report(props.rating.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button variant="ghost" size="sm">
                <Flag aria-hidden="true" />
                Report
            </Button>
        </DialogTrigger>

        <DialogContent>
            <DialogHeader>
                <DialogTitle>Report this review</DialogTitle>
                <DialogDescription>
                    A moderator reads every report. Disagreeing with a review is
                    not on its own a reason to take it down.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="report-reason">What is wrong with it?</Label>
                    <select
                        id="report-reason"
                        v-model="form.reason"
                        class="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                    >
                        <option
                            v-for="reason in reasons"
                            :key="reason.value"
                            :value="reason.value"
                        >
                            {{ reason.label }}
                        </option>
                    </select>
                    <InputError :message="form.errors.reason" />
                </div>

                <div class="space-y-2">
                    <Label for="report-details"
                        >Anything else? (optional)</Label
                    >
                    <textarea
                        id="report-details"
                        v-model="form.details"
                        rows="3"
                        class="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                        maxlength="1000"
                    />
                    <InputError :message="form.errors.details" />
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        Send report
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
