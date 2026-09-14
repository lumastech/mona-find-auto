<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { MessageSquare } from '@lucide/vue';
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
import { login } from '@/routes';
import sellers from '@/routes/sellers';

/**
 * "Contact seller" — a message that actually reaches them.
 *
 * Replies and a full thread view arrive with the messaging module; what this
 * does today is deliver the message and leave a record of it. A button that
 * pretended to send would be worse than no button.
 *
 * It sits beside the contact details rather than instead of them: a buyer who
 * wants to ring the shop should ring the shop, and a guest sees the labels
 * blurred with a prompt to log in.
 */
const props = defineProps<{
    sellerSlug: string;
    sellerName: string;
    productId?: number;
    isAuthenticated: boolean;
}>();

const open = ref(false);

const form = useForm({
    message: '',
    product_id: props.productId ?? null,
});

const submit = (): void => {
    form.post(sellers.enquiries.store(props.sellerSlug).url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset('message');
        },
    });
};
</script>

<template>
    <Button v-if="!isAuthenticated" variant="outline" class="w-full" as-child>
        <Link :href="login().url">
            <MessageSquare class="size-4" aria-hidden="true" />
            Log in to message this seller
        </Link>
    </Button>

    <Dialog v-else v-model:open="open">
        <DialogTrigger as-child>
            <Button type="button" variant="outline" class="w-full">
                <MessageSquare class="size-4" aria-hidden="true" />
                Message this seller
            </Button>
        </DialogTrigger>

        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Message {{ sellerName }}</DialogTitle>
                <DialogDescription>
                    They will see this alongside your name. Replies arrive by
                    email for now.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <label for="enquiry-message" class="sr-only">
                        Your message
                    </label>
                    <textarea
                        id="enquiry-message"
                        v-model="form.message"
                        rows="4"
                        maxlength="2000"
                        required
                        placeholder="Is this the one with the wiring loom, and do you deliver to Kabwe?"
                        class="border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                    />
                    <InputError :message="form.errors.message" />
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        Send message
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
