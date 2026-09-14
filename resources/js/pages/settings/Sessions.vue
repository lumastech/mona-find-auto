<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import sessionRoutes from '@/routes/sessions';
import type { ApiToken, BrowserSession } from '@/types';

defineProps<{
    sessions: BrowserSession[];
    apiTokens: ApiToken[];
    tracksSessions: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Devices', href: sessionRoutes.index() }],
    },
});
</script>

<template>
    <Head title="Devices" />

    <h1 class="sr-only">Devices</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Devices"
            description="Where your account is signed in. Sign out anything you do not recognise."
        />

        <p v-if="!tracksSessions" class="text-muted-foreground text-sm">
            This deployment does not keep a record of browser sessions, so there
            is nothing to list here.
        </p>

        <ul v-else-if="sessions.length" class="divide-border divide-y">
            <li
                v-for="session in sessions"
                :key="session.id"
                class="flex flex-wrap items-center justify-between gap-3 py-4"
            >
                <div class="space-y-1 text-sm">
                    <p class="flex items-center gap-2 font-medium">
                        {{ session.device }}
                        <Badge v-if="session.is_current" variant="secondary">
                            This device
                        </Badge>
                    </p>
                    <p class="text-muted-foreground">
                        {{ session.ip_address ?? 'Unknown address' }} · last
                        active {{ session.last_active }}
                    </p>
                </div>

                <Button
                    v-if="!session.is_current"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="sessionRoutes.destroy(session.id)"
                        method="delete"
                        as="button"
                    >
                        Sign out
                    </Link>
                </Button>
            </li>
        </ul>

        <p v-else class="text-muted-foreground text-sm">
            No other devices are signed in.
        </p>

        <div v-if="apiTokens.length" class="space-y-3">
            <Heading
                variant="small"
                title="Mobile app"
                description="Devices signed in through the MonaFind app."
            />

            <ul class="divide-border divide-y text-sm">
                <li
                    v-for="token in apiTokens"
                    :key="token.id"
                    class="flex items-center justify-between gap-3 py-3"
                >
                    <span class="font-medium">{{ token.name }}</span>
                    <span class="text-muted-foreground">
                        {{
                            token.last_used
                                ? `Last used ${token.last_used}`
                                : 'Never used'
                        }}
                    </span>
                </li>
            </ul>
        </div>

        <div v-if="sessions.length > 1 || apiTokens.length">
            <Button variant="destructive" as-child>
                <Link
                    :href="sessionRoutes.destroyOthers()"
                    method="delete"
                    as="button"
                >
                    Sign out everywhere else
                </Link>
            </Button>
        </div>
    </div>
</template>
