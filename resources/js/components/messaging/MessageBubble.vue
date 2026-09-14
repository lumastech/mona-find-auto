<script setup lang="ts">
import { FileText, Info, Paperclip } from '@lucide/vue';
import { computed } from 'vue';
import type { ThreadMessage } from '@/types/messaging';

/**
 * One message.
 *
 * The redaction notice is the part that earns its place. When the screen has
 * taken a phone number out, the recipient sees "[removed]" in the middle of a
 * sentence, and a person who cannot tell whether the platform edited it or
 * the sender typed something odd will go and ask on WhatsApp — which is
 * exactly what the screen exists to avoid. So the message says which kind of
 * thing was removed, in the sender's bubble as well as the recipient's.
 */
const props = defineProps<{ message: ThreadMessage }>();

const images = computed(() =>
    props.message.attachments.filter((file) =>
        file.mime_type.startsWith('image/'),
    ),
);

const documents = computed(() =>
    props.message.attachments.filter(
        (file) => !file.mime_type.startsWith('image/'),
    ),
);

const sentAt = computed(() => {
    if (props.message.created_at === null) {
        return '';
    }

    return new Date(props.message.created_at).toLocaleString('en-GB', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
});
</script>

<template>
    <!-- A line the platform wrote: centred, quiet, not a party to the conversation. -->
    <li
        v-if="message.is_from_platform"
        class="text-muted-foreground flex items-center justify-center gap-2 py-1 text-xs"
    >
        <Info class="size-3.5 shrink-0" />
        <span>{{ message.body }}</span>
    </li>

    <li
        v-else
        class="flex"
        :class="message.is_mine ? 'justify-end' : 'justify-start'"
    >
        <div class="max-w-[85%] sm:max-w-[70%]">
            <p
                v-if="!message.is_mine && message.author?.name"
                class="text-muted-foreground mb-1 px-1 text-xs font-medium"
            >
                {{ message.author.name }}
            </p>

            <div
                class="rounded-2xl px-3.5 py-2.5 text-sm break-words"
                :class="
                    message.is_mine
                        ? 'bg-primary text-primary-foreground rounded-br-sm'
                        : 'bg-muted text-foreground rounded-bl-sm'
                "
            >
                <p class="whitespace-pre-wrap">{{ message.body }}</p>

                <div v-if="images.length" class="mt-2 grid grid-cols-2 gap-1.5">
                    <a
                        v-for="image in images"
                        :key="image.id"
                        :href="image.url"
                        target="_blank"
                        rel="noopener"
                        class="block overflow-hidden rounded-lg"
                    >
                        <img
                            :src="image.thumb_url ?? image.url"
                            :alt="image.name"
                            loading="lazy"
                            class="h-24 w-full object-cover"
                        />
                    </a>
                </div>

                <ul v-if="documents.length" class="mt-2 space-y-1">
                    <li v-for="file in documents" :key="file.id">
                        <a
                            :href="file.url"
                            target="_blank"
                            rel="noopener"
                            class="flex items-center gap-1.5 text-xs underline underline-offset-2"
                        >
                            <FileText class="size-3.5 shrink-0" />
                            <span class="truncate">{{ file.name }}</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!--
                Said plainly, and to both sides. The sender needs to know what
                was removed as much as the recipient does.
            -->
            <p
                v-if="message.was_redacted"
                class="text-muted-foreground mt-1 flex items-center gap-1 px-1 text-[11px]"
                :class="message.is_mine ? 'justify-end' : 'justify-start'"
            >
                <Paperclip class="size-3" />
                {{ message.redactions.join(' · ') }}
            </p>

            <p
                class="text-muted-foreground mt-0.5 px-1 text-[11px]"
                :class="message.is_mine ? 'text-right' : 'text-left'"
            >
                {{ sentAt }}
            </p>
        </div>
    </li>
</template>
