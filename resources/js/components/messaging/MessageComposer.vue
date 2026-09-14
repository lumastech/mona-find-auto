<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Paperclip, Send, ShieldAlert, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import threads from '@/routes/threads';
import type { MessageThread } from '@/types/messaging';

/**
 * Writing a message, and one warning.
 *
 * The warning fires BEFORE the send, not after. Until the thread's order is
 * paid the screen removes phone numbers, emails and links — and being told
 * afterwards, with the number already gone from what you wrote, reads as the
 * platform having lost your message. So the composer watches what is being
 * typed and says so while there is still time to say it differently.
 *
 * The detection here is deliberately looser than the server's: it is a hint,
 * not a rule, and the server's screen is what actually decides. A hint that
 * fires slightly too often costs a line of text; one that stays silent when
 * the server is about to redact is the failure that matters.
 */
const props = defineProps<{ thread: MessageThread }>();

const form = useForm<{ body: string; attachments: File[] }>({
    body: '',
    attachments: [],
});

const fileInput = ref<HTMLInputElement | null>(null);

/** A phone-shaped run of digits, an email, or something that looks like a link. */
const looksLikeContact = computed(() => {
    const body = form.body;

    return (
        /\+?\d[\d\s().-]{6,}\d/.test(body) ||
        /[^\s@]+@[^\s@]+\.[^\s@]+/.test(body) ||
        /(https?:\/\/|www\.|\.(com|net|org|zm|io|shop)\b)/i.test(body)
    );
});

const willBeRedacted = computed(
    () => !props.thread.allows_contact_details && looksLikeContact.value,
);

const chooseFiles = (): void => fileInput.value?.click();

const onFiles = (event: Event): void => {
    const input = event.target as HTMLInputElement;

    form.attachments = [
        ...form.attachments,
        ...Array.from(input.files ?? []),
    ].slice(0, 5);

    input.value = '';
};

const removeFile = (index: number): void => {
    form.attachments = form.attachments.filter((_, i) => i !== index);
};

const submit = (): void => {
    if (form.body.trim() === '') {
        return;
    }

    form.post(threads.reply.url({ thread: props.thread.id }), {
        preserveScroll: true,
        forceFormData: form.attachments.length > 0,
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <form
        v-if="!thread.is_closed"
        class="border-t p-3 sm:p-4"
        @submit.prevent="submit"
    >
        <div
            v-if="willBeRedacted"
            class="mb-2 flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
            role="status"
        >
            <ShieldAlert class="mt-px size-4 shrink-0" />
            <p>
                Phone numbers, email addresses and links are removed from
                messages until the order is paid. Once you have paid, you can
                swap details here freely.
            </p>
        </div>

        <ul v-if="form.attachments.length" class="mb-2 flex flex-wrap gap-1.5">
            <li
                v-for="(file, index) in form.attachments"
                :key="`${file.name}-${index}`"
                class="bg-muted flex items-center gap-1.5 rounded-full py-1 pr-1 pl-2.5 text-xs"
            >
                <span class="max-w-40 truncate">{{ file.name }}</span>
                <button
                    type="button"
                    class="hover:bg-background rounded-full p-0.5"
                    :aria-label="`Remove ${file.name}`"
                    @click="removeFile(index)"
                >
                    <X class="size-3" />
                </button>
            </li>
        </ul>

        <div class="flex items-end gap-2">
            <label class="sr-only" for="message-body">Your message</label>
            <textarea
                id="message-body"
                v-model="form.body"
                rows="2"
                maxlength="2000"
                placeholder="Write a message…"
                class="border-input bg-background focus-visible:ring-ring min-h-11 flex-1 resize-y rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                @keydown.enter.exact.prevent="submit"
            />

            <input
                ref="fileInput"
                type="file"
                multiple
                accept="image/jpeg,image/png,image/webp,image/avif,application/pdf"
                class="hidden"
                @change="onFiles"
            />

            <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label="Attach a photo or PDF"
                @click="chooseFiles"
            >
                <Paperclip class="size-5" />
            </Button>

            <Button
                type="submit"
                size="icon"
                :disabled="form.processing || form.body.trim() === ''"
                aria-label="Send"
            >
                <Send class="size-4" />
            </Button>
        </div>

        <InputError :message="form.errors.body" class="mt-1" />
        <InputError :message="form.errors.attachments" class="mt-1" />
    </form>

    <p
        v-else
        class="text-muted-foreground border-t px-4 py-6 text-center text-sm"
    >
        This conversation is closed.
    </p>
</template>
