<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCircle2, Clock, RefreshCw, XCircle } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Money from '@/components/Money.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

/**
 * Where the buyer waits, and where they end up.
 *
 * One page for pending, success and failure rather than three, because the
 * state can change while the buyer is looking at it — a mobile-money prompt
 * sitting on a handset becomes a paid order the moment they key in a PIN.
 *
 * The polling backs off (3s → 5s → 8s → 15s, capped) rather than hammering a
 * fixed interval: most payments resolve in the first few seconds, and the
 * ones that do not are waiting on a person who has put their phone down.
 * It stops entirely after five minutes, by which point the server-side
 * stuck-payment sweep owns the problem and the buyer can safely close the tab.
 */
const props = defineProps<{
    group: {
        publicId: string;
        status: string;
        statusLabel: string;
        totalNgwee: number;
        isPaid: boolean;
        orders: Array<{
            number: string;
            seller: string;
            totalNgwee: number;
            url: string;
        }>;
    };
    payment: {
        reference: string;
        status: string;
        attempt: number;
        channel: string | null;
        failureReason: string | null;
        amountNgwee: number;
    } | null;
    isPending: boolean;
    retryUrl: string | null;
    pollUrl: string;
}>();

const isPolling = ref(props.isPending);
const attempts = ref(0);

let timer: ReturnType<typeof setTimeout> | undefined;

const backoffMs = computed(() => {
    const schedule = [3000, 5000, 8000, 15000];

    return schedule[Math.min(attempts.value, schedule.length - 1)];
});

/** Give up after five minutes; the server keeps watching regardless. */
const givenUp = computed(() => attempts.value > 30);

function schedulePoll(): void {
    if (!isPolling.value || givenUp.value) {
        return;
    }

    timer = setTimeout(() => {
        attempts.value += 1;

        router.reload({
            only: ['group', 'payment', 'isPending', 'retryUrl'],
            onSuccess: () => {
                if (!props.isPending) {
                    isPolling.value = false;

                    return;
                }

                schedulePoll();
            },
            onError: () => schedulePoll(),
        });
    }, backoffMs.value);
}

onMounted(() => schedulePoll());

onBeforeUnmount(() => {
    if (timer) {
        clearTimeout(timer);
    }
});
</script>

<template>
    <Head :title="group.isPaid ? 'Payment received' : 'Payment status'" />

    <div class="mx-auto w-full max-w-2xl px-4 py-8">
        <!-- Paid -->
        <div v-if="group.isPaid" class="text-center">
            <CheckCircle2
                class="mx-auto size-12 text-emerald-600"
                aria-hidden="true"
            />
            <h1 class="mt-4 text-2xl font-semibold tracking-tight">
                Payment received
            </h1>
            <p class="text-muted-foreground mt-1 text-sm">
                We have sent your order to
                {{ group.orders.length === 1 ? 'the seller' : 'each seller' }}.
                You will be asked to confirm once you have the parts.
            </p>
        </div>

        <!-- Still waiting -->
        <div v-else-if="isPending" class="text-center">
            <Clock
                class="mx-auto size-12 animate-pulse text-amber-500"
                aria-hidden="true"
            />
            <h1 class="mt-4 text-2xl font-semibold tracking-tight">
                Waiting for your payment
            </h1>
            <p class="text-muted-foreground mt-1 text-sm">
                If you are paying by mobile money, approve the prompt on your
                phone. This page updates on its own.
            </p>

            <Alert v-if="givenUp" class="mt-6 text-left">
                <AlertTitle>Still waiting</AlertTitle>
                <AlertDescription>
                    You can safely close this page. We keep checking with the
                    payment provider, and your order will move on its own once
                    the money lands.
                </AlertDescription>
            </Alert>
        </div>

        <!-- Failed -->
        <div v-else class="text-center">
            <XCircle
                class="text-destructive mx-auto size-12"
                aria-hidden="true"
            />
            <h1 class="mt-4 text-2xl font-semibold tracking-tight">
                Payment did not go through
            </h1>
            <p class="text-muted-foreground mt-1 text-sm">
                {{ payment?.failureReason ?? 'The payment was not completed.' }}
            </p>
            <p class="text-muted-foreground mt-1 text-sm">
                Nothing has been charged, and your order is still here.
            </p>
        </div>

        <Card class="mt-8">
            <CardContent class="space-y-3 pt-6">
                <div class="flex items-baseline justify-between text-sm">
                    <span class="text-muted-foreground">Amount</span>
                    <Money :amount="group.totalNgwee" class="font-medium" />
                </div>
                <div
                    v-if="payment"
                    class="flex items-baseline justify-between text-sm"
                >
                    <span class="text-muted-foreground">Reference</span>
                    <span class="font-mono text-xs">{{
                        payment.reference
                    }}</span>
                </div>
                <div
                    v-if="payment?.channel"
                    class="flex items-baseline justify-between text-sm"
                >
                    <span class="text-muted-foreground">Paid by</span>
                    <span>{{ payment.channel }}</span>
                </div>
            </CardContent>
        </Card>

        <div v-if="group.isPaid" class="mt-6 space-y-2">
            <Link
                v-for="order in group.orders"
                :key="order.number"
                :href="order.url"
                class="hover:bg-accent flex items-center justify-between rounded-lg border p-4 text-sm transition-colors"
            >
                <div>
                    <p class="font-medium">{{ order.seller }}</p>
                    <p class="text-muted-foreground text-xs">
                        {{ order.number }}
                    </p>
                </div>
                <Money :amount="order.totalNgwee" />
            </Link>
        </div>

        <Button
            v-if="retryUrl && !isPending"
            as-child
            class="mt-6 w-full"
            size="lg"
        >
            <Link :href="retryUrl">
                <RefreshCw class="size-4" aria-hidden="true" />
                Try paying again
            </Link>
        </Button>
    </div>
</template>
