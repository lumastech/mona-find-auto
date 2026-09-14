<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { AlertTriangle } from '@lucide/vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import orders from '@/routes/orders';
import type { OrderSummary } from '@/types';

/**
 * The buyer reporting a problem.
 *
 * Photographs are asked for rather than merely allowed: a dispute about a
 * cracked housing is decided by looking at the housing, and a moderator with
 * no picture has to chase the buyer by phone before they can decide anything.
 *
 * There is no "withdraw" here to match. A dispute is a claim about what
 * happened, and one that can be rewritten after the seller has answered is
 * not evidence — a buyer who was mistaken says so, and the moderator resolves
 * it as a release.
 */
const props = defineProps<{
    order: OrderSummary;
    reasons: { value: string; label: string; guidance: string }[];
}>();

const open = ref(false);

const form = useForm<{
    reason: string;
    details: string;
    photos: File[];
}>({
    reason: props.reasons[0]?.value ?? 'other',
    details: '',
    photos: [],
});

const onFiles = (event: Event): void => {
    form.photos = Array.from((event.target as HTMLInputElement).files ?? []);
};

const submit = (): void => {
    form.post(orders.disputes.store(props.order.number).url, {
        forceFormData: true,
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
            <Button variant="outline" size="sm" data-test="open-dispute">
                <AlertTriangle class="size-4" aria-hidden="true" />
                Report a problem
            </Button>
        </DialogTrigger>

        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle
                    >Report a problem with {{ order.number }}</DialogTitle
                >
                <DialogDescription>
                    This order will not complete and the seller will not be paid
                    until MonaFind has looked at it.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <fieldset class="space-y-2">
                    <legend class="text-sm font-medium">
                        What went wrong?
                    </legend>

                    <label
                        v-for="reason in reasons"
                        :key="reason.value"
                        class="hover:bg-accent flex cursor-pointer items-start gap-3 rounded-md border p-3"
                        :class="
                            form.reason === reason.value ? 'border-primary' : ''
                        "
                    >
                        <input
                            v-model="form.reason"
                            type="radio"
                            name="reason"
                            :value="reason.value"
                            class="mt-1"
                        />
                        <span class="text-sm">
                            <span class="block font-medium">{{
                                reason.label
                            }}</span>
                            <span class="text-muted-foreground">{{
                                reason.guidance
                            }}</span>
                        </span>
                    </label>

                    <InputError :message="form.errors.reason" />
                </fieldset>

                <div class="space-y-2">
                    <Label for="dispute-details">What happened</Label>
                    <textarea
                        id="dispute-details"
                        v-model="form.details"
                        rows="4"
                        class="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-1 focus-visible:outline-none"
                        placeholder="Tell us in a sentence or two, so we can act on it without calling you back."
                    />
                    <InputError :message="form.errors.details" />
                </div>

                <div class="space-y-2">
                    <Label for="dispute-photos">Photos (up to 5)</Label>
                    <Input
                        id="dispute-photos"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        multiple
                        @change="onFiles"
                    />
                    <p class="text-muted-foreground text-xs">
                        A photo of the part settles most of these on the spot.
                    </p>
                    <InputError :message="form.errors.photos" />
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        Send this to MonaFind
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
