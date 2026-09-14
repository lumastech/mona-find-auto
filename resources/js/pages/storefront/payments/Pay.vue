<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    CreditCard,
    Loader2,
    Lock,
    ShieldCheck,
    Smartphone,
} from '@lucide/vue';
import { onMounted, ref } from 'vue';
import Money from '@/components/Money.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';

/**
 * The pay screen.
 *
 * ## The widget is an overlay, not a redirect
 *
 * Lenco's inline script opens over this page, so the buyer never leaves
 * MonaFind and the back button keeps working. The script is loaded on demand
 * rather than in the document head: it is a third-party script on the money
 * path, and it has no business executing on pages that are not asking for it.
 *
 * ## onSuccess is a hint, not a result
 *
 * All three callbacks do the same thing — ask our server to go and check.
 * Nothing the widget hands back is passed on, because the server decides what
 * happened by asking Lenco directly. A buyer with the console open can call
 * these all day and achieve nothing but extra status checks.
 *
 * onClose is deliberately treated the same way rather than as an abandonment:
 * a mobile-money prompt is often still sitting on the handset when the
 * overlay closes, and that payment can still succeed.
 */
interface LencoConfig {
    publicKey: string;
    widgetUrl: string;
    environment: string;
    reference: string;
    attempt: number;
    amount: string;
    amountNgwee: number;
    currency: string;
    channels: string[];
    bearer: string;
    label: string;
    customer: Record<string, string>;
    billing: Record<string, string>;
    verifyUrl: string;
    statusUrl: string;
}

const props = defineProps<{
    group: {
        publicId: string;
        totalNgwee: number;
        itemsTotalNgwee: number;
        deliveryTotalNgwee: number;
        sellers: Array<{ number: string; name: string; totalNgwee: number }>;
    };
    lenco: LencoConfig;
}>();

const isBusy = ref(false);
const scriptFailed = ref(false);

declare global {
    interface Window {
        LencoPay?: {
            getPaid(config: Record<string, unknown>): void;
        };
    }
}

/**
 * Load the widget script once, resolving when it is usable.
 *
 * Reused across retries so that a buyer whose card is declined and tries
 * again does not download it a second time.
 */
function loadWidget(): Promise<void> {
    if (window.LencoPay) {
        return Promise.resolve();
    }

    return new Promise((resolve, reject) => {
        const existing = document.querySelector<HTMLScriptElement>(
            `script[src="${props.lenco.widgetUrl}"]`,
        );

        if (existing) {
            existing.addEventListener('load', () => resolve());
            existing.addEventListener('error', () =>
                reject(new Error('load failed')),
            );

            return;
        }

        const script = document.createElement('script');
        script.src = props.lenco.widgetUrl;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('load failed'));

        document.head.appendChild(script);
    });
}

/**
 * Ask our server what happened, then go where it says.
 *
 * Sends no payload at all — see the component docblock.
 */
function verify(): void {
    isBusy.value = true;

    router.post(
        props.lenco.verifyUrl,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                isBusy.value = false;
                router.visit(props.lenco.statusUrl);
            },
        },
    );
}

async function pay(): Promise<void> {
    isBusy.value = true;

    try {
        await loadWidget();
    } catch {
        scriptFailed.value = true;
        isBusy.value = false;

        return;
    }

    window.LencoPay?.getPaid({
        key: props.lenco.publicKey,
        reference: props.lenco.reference,
        email: props.lenco.customer.email,
        amount: props.lenco.amount,
        currency: props.lenco.currency,
        channels: props.lenco.channels,
        bearer: props.lenco.bearer,
        label: props.lenco.label,
        customer: props.lenco.customer,
        billing: props.lenco.billing,

        /* All three routes lead to the same server-side check. */
        onSuccess: () => verify(),
        onConfirmationPending: () => router.visit(props.lenco.statusUrl),
        onClose: () => {
            isBusy.value = false;
        },
    });
}

onMounted(() => {
    /* Warm the script while the buyer reads the summary. */
    void loadWidget().catch(() => {
        scriptFailed.value = true;
    });
});
</script>

<template>
    <Head title="Pay for your order" />

    <div class="mx-auto w-full max-w-2xl px-4 py-8">
        <h1 class="text-2xl font-semibold tracking-tight">
            Pay for your order
        </h1>
        <p class="text-muted-foreground mt-1 text-sm">
            One payment covers every shop in this order.
        </p>

        <Alert v-if="scriptFailed" variant="destructive" class="mt-6">
            <AlertDescription>
                We could not load the secure payment window. Check your
                connection and try again.
            </AlertDescription>
        </Alert>

        <Card class="mt-6">
            <CardHeader>
                <h2 class="font-medium">What you are paying for</h2>
            </CardHeader>
            <CardContent class="space-y-3">
                <div
                    v-for="seller in group.sellers"
                    :key="seller.number"
                    class="flex items-baseline justify-between gap-4 text-sm"
                >
                    <div>
                        <p class="font-medium">{{ seller.name }}</p>
                        <p class="text-muted-foreground text-xs">
                            {{ seller.number }}
                        </p>
                    </div>
                    <Money :amount="seller.totalNgwee" />
                </div>

                <Separator />

                <div
                    class="text-muted-foreground flex items-baseline justify-between text-sm"
                >
                    <span>Items</span>
                    <Money :amount="group.itemsTotalNgwee" />
                </div>
                <div
                    v-if="group.deliveryTotalNgwee > 0"
                    class="text-muted-foreground flex items-baseline justify-between text-sm"
                >
                    <span>Delivery</span>
                    <Money :amount="group.deliveryTotalNgwee" />
                </div>

                <Separator />

                <div
                    class="flex items-baseline justify-between text-base font-semibold"
                >
                    <span>Total</span>
                    <Money :amount="group.totalNgwee" />
                </div>
            </CardContent>
        </Card>

        <Button class="mt-6 w-full" size="lg" :disabled="isBusy" @click="pay">
            <Loader2 v-if="isBusy" class="size-4 animate-spin" />
            <Lock v-else class="size-4" />
            Pay <Money :amount="group.totalNgwee" />
        </Button>

        <div
            class="text-muted-foreground mt-4 flex flex-wrap items-center justify-center gap-4 text-xs"
        >
            <span class="inline-flex items-center gap-1.5">
                <CreditCard class="size-3.5" aria-hidden="true" /> Card
            </span>
            <span class="inline-flex items-center gap-1.5">
                <Smartphone class="size-3.5" aria-hidden="true" /> Mobile money
            </span>
            <span class="inline-flex items-center gap-1.5">
                <ShieldCheck class="size-3.5" aria-hidden="true" />
                Held by MonaFind until you confirm
            </span>
        </div>

        <p
            v-if="lenco.environment !== 'live'"
            class="mt-4 text-center text-xs text-amber-600"
        >
            Test mode — no real money will move.
        </p>
    </div>
</template>
