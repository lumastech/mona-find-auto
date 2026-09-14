<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { redirect } from '@/routes/social';
import type { SocialProviderOption } from '@/types';

/**
 * Google and Facebook sign-in.
 *
 * Only providers this deployment has credentials for reach the page, so an
 * empty list renders nothing at all rather than a button that would fail.
 *
 * These leave the SPA for the provider, so they are real anchors, not
 * Inertia links.
 */
defineProps<{
    providers: SocialProviderOption[];
    action?: string;
}>();
</script>

<template>
    <div v-if="providers.length" class="grid gap-4">
        <div
            class="grid gap-2"
            :class="providers.length > 1 && 'sm:grid-cols-2'"
        >
            <Button
                v-for="provider in providers"
                :key="provider.value"
                variant="outline"
                type="button"
                as-child
            >
                <a :href="redirect.url({ provider: provider.value })">
                    {{ action ?? 'Continue' }} with {{ provider.label }}
                </a>
            </Button>
        </div>

        <div class="relative text-center text-sm">
            <span
                class="bg-background text-muted-foreground relative z-10 px-2"
            >
                or use your email address
            </span>
            <span class="bg-border absolute inset-x-0 top-1/2 h-px" />
        </div>
    </div>
</template>
