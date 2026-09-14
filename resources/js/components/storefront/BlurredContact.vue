<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';
import type { SellerContact } from '@/types';

/**
 * A seller's contact details, blurred for guests.
 *
 * The blur here is decoration, not the protection. The server has already
 * replaced every value with a mask for a guest, so there is nothing real in
 * the page to reveal with a devtools inspector — the filter only makes it
 * look the way it reads.
 */
defineProps<{
    contact: SellerContact;
    businessName?: string;
}>();
</script>

<template>
    <div class="space-y-3">
        <dl class="space-y-3">
            <div
                v-for="field in contact.fields"
                :key="field.key"
                class="grid gap-1 sm:grid-cols-3 sm:items-baseline sm:gap-3"
            >
                <dt class="text-muted-foreground text-sm">{{ field.label }}</dt>
                <dd
                    class="font-medium sm:col-span-2"
                    :class="
                        contact.visible
                            ? ''
                            : 'text-muted-foreground blur-[3px] select-none'
                    "
                    :aria-hidden="!contact.visible"
                >
                    <a
                        v-if="contact.visible && field.key === 'phone'"
                        :href="`tel:${field.value}`"
                        class="hover:underline"
                    >
                        {{ field.value }}
                    </a>
                    <a
                        v-else-if="contact.visible && field.key === 'email'"
                        :href="`mailto:${field.value}`"
                        class="hover:underline"
                    >
                        {{ field.value }}
                    </a>
                    <span v-else>{{ field.value }}</span>
                </dd>
            </div>
        </dl>

        <div
            v-if="!contact.visible"
            class="bg-muted/50 flex flex-wrap items-center gap-3 rounded-lg border p-3"
        >
            <Lock
                class="text-muted-foreground size-4 shrink-0"
                aria-hidden="true"
            />
            <p class="text-muted-foreground min-w-0 flex-1 text-sm">
                Log in to see how to reach
                {{ businessName ?? 'this seller' }}.
            </p>
            <Button as-child size="sm">
                <Link :href="login()">{{ contact.prompt }}</Link>
            </Button>
        </div>
    </div>
</template>
