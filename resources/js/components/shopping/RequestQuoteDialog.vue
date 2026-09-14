<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { FileText } from '@lucide/vue';
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
import { login } from '@/routes';
import quotations from '@/routes/quotations';
import type { ProductVariant } from '@/types';

/**
 * "What would forty of these cost me?"
 *
 * A great deal of Zambian parts trade is this conversation, and before
 * MonaFind it happened on WhatsApp where neither side could prove afterwards
 * what had been agreed. Asking here means the answer arrives as a price with
 * a date on it, and accepting it puts that price — not the shelf price — into
 * the cart.
 *
 * The message is optional. "How much for 40?" is a complete request.
 */
const props = defineProps<{
    variant: ProductVariant;
    isAuthenticated: boolean;
}>();

const open = ref(false);

const form = useForm({
    variant_id: props.variant.id,
    quantity: 10,
    message: '',
});

const submit = (): void => {
    form.variant_id = props.variant.id;

    form.post(quotations.store().url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset('message');
        },
    });
};
</script>

<template>
    <Button v-if="!isAuthenticated" variant="outline" as-child>
        <Link :href="login().url">
            <FileText class="size-4" aria-hidden="true" />
            Log in to request a quote
        </Link>
    </Button>

    <Dialog v-else v-model:open="open">
        <DialogTrigger as-child>
            <Button type="button" variant="outline">
                <FileText class="size-4" aria-hidden="true" />
                Request a quote
            </Button>
        </DialogTrigger>

        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Ask this seller for a price</DialogTitle>
                <DialogDescription>
                    Buying several? Ask what the seller can do. They will come
                    back with a price and a date it stands until — accept it and
                    it goes into your cart at that price.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="quote-quantity">How many do you need?</Label>
                    <Input
                        id="quote-quantity"
                        v-model.number="form.quantity"
                        type="number"
                        min="1"
                        max="10000"
                        required
                    />
                    <InputError :message="form.errors.quantity" />
                </div>

                <div class="space-y-2">
                    <Label for="quote-message">
                        Anything the seller should know?
                        <span class="text-muted-foreground font-normal">
                            (optional)
                        </span>
                    </Label>
                    <textarea
                        id="quote-message"
                        v-model="form.message"
                        rows="3"
                        maxlength="1000"
                        placeholder="For a 2012 Hilux — can you deliver to Kabwe?"
                        class="border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                    />
                    <InputError :message="form.errors.message" />
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        Send request
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
