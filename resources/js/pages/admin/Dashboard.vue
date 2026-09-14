<script setup lang="ts">
import { Head, Link, Deferred } from '@inertiajs/vue3';
import { ArrowRight, ShieldAlert } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import adminStaff from '@/routes/admin/staff';
import type { ConsoleCounter, ConsoleShortcut } from '@/types';

/**
 * The staff console's home screen: what is waiting, and nothing else.
 *
 * Every number here is contributed by the module that owns the queue behind
 * it, so this page renders whatever came back rather than knowing what a
 * payout batch or a stale listing is. A tile the viewer's role cannot act on
 * never arrives — a dashboard full of doors that refuse you is one nobody
 * reads.
 *
 * Queues with something in them sort to the front. The screen is a to-do
 * list, and a to-do list that opens on nine zeroes buries the one number that
 * needed somebody this morning.
 */
const props = defineProps<{
    counters: ConsoleCounter[];
    shortcuts: ConsoleShortcut[];
    twoFactorOutstanding?: number | null;
}>();

const sorted = computed(() =>
    [...props.counters].sort((a, b) => {
        const weight = (counter: ConsoleCounter): number =>
            counter.value === 0
                ? 0
                : { critical: 3, warning: 2, neutral: 1 }[counter.tone];

        return weight(b) - weight(a);
    }),
);

const outstanding = computed(() =>
    props.counters.reduce((total, counter) => total + counter.value, 0),
);

const toneClasses = (counter: ConsoleCounter): string => {
    if (counter.value === 0) {
        return 'text-muted-foreground';
    }

    return counter.tone === 'critical'
        ? 'text-destructive'
        : counter.tone === 'warning'
          ? 'text-amber-600 dark:text-amber-500'
          : 'text-foreground';
};
</script>

<template>
    <Head title="Staff console" />

    <div class="space-y-6 p-4">
        <Heading
            title="Staff console"
            :description="
                outstanding === 0
                    ? 'Nothing is waiting. Everything below is clear.'
                    : `${outstanding} item${outstanding === 1 ? '' : 's'} waiting across your queues.`
            "
        />

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="counter in sorted"
                :key="counter.key"
                :href="counter.href"
                class="focus-visible:ring-ring rounded-xl focus-visible:ring-2 focus-visible:outline-none"
            >
                <Card class="hover:border-primary/50 h-full transition-colors">
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm font-medium">
                            {{ counter.label }}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p
                            class="text-3xl font-semibold tabular-nums"
                            :class="toneClasses(counter)"
                        >
                            {{ counter.value }}
                        </p>
                        <p
                            v-if="counter.hint"
                            class="text-muted-foreground mt-1 text-xs"
                        >
                            {{ counter.hint }}
                        </p>
                    </CardContent>
                </Card>
            </Link>
        </div>

        <!--
            Deferred because it walks every staff account; the queues above are
            what somebody opened this screen for.
        -->
        <Deferred data="twoFactorOutstanding">
            <template #fallback>
                <Skeleton class="h-16 w-full animate-pulse rounded-xl" />
            </template>

            <Card v-if="twoFactorOutstanding" class="border-destructive/40">
                <CardHeader class="flex-row items-center gap-3 space-y-0">
                    <ShieldAlert class="text-destructive size-5 shrink-0" />
                    <div>
                        <CardTitle class="text-sm">
                            {{ twoFactorOutstanding }} staff
                            {{
                                twoFactorOutstanding === 1
                                    ? 'account has'
                                    : 'accounts have'
                            }}
                            not set up two-factor authentication
                        </CardTitle>
                        <CardDescription>
                            They are held at the enrolment screen and can reach
                            nothing until they finish.
                            <Link
                                :href="adminStaff.index().url"
                                class="underline underline-offset-4"
                            >
                                Open staff
                            </Link>
                        </CardDescription>
                    </div>
                </CardHeader>
            </Card>
        </Deferred>

        <Card>
            <CardHeader>
                <CardTitle>Everything else</CardTitle>
                <CardDescription>
                    The screens with no queue behind them.
                </CardDescription>
            </CardHeader>
            <CardContent class="grid gap-3 sm:grid-cols-2">
                <Link
                    v-for="shortcut in shortcuts"
                    :key="shortcut.href"
                    :href="shortcut.href"
                    class="hover:bg-accent flex items-start gap-3 rounded-lg border p-3 transition-colors"
                >
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">{{ shortcut.title }}</p>
                        <p class="text-muted-foreground mt-0.5 text-xs">
                            {{ shortcut.description }}
                        </p>
                    </div>
                    <ArrowRight
                        class="text-muted-foreground mt-0.5 size-4 shrink-0"
                    />
                </Link>
            </CardContent>
        </Card>
    </div>
</template>
