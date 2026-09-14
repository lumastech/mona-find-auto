<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { CreditCard } from '@lucide/vue';
import { ref } from 'vue';
import Money from '@/components/Money.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

/**
 * Refunds that code could not finish on its own.
 *
 * Card reversals go through Lenco's own process by hand, and a refund whose
 * transfer bounced needs sending another way. Marking one done asserts that
 * money really moved, so it writes an audit row against the person who said so.
 */
defineProps<{
    refunds: Array<{
        reference: string;
        status: string;
        statusLabel: string;
        method: string;
        methodLabel: string;
        reasonLabel: string | null;
        amountNgwee: number;
        needsManualProcessing: boolean;
        destination: string | null;
        failureReason: string | null;
        order: { number: string; seller: string | null; url: string };
        buyer: string | null;
        createdAt: string | null;
    }>;
    pagination: { currentPage: number; lastPage: number; total: number };
}>();

const openReference = ref<string | null>(null);
const form = useForm({ note: '' });

function complete(reference: string): void {
    form.post(`/admin/refunds/${reference}/complete`, {
        preserveScroll: true,
        onSuccess: () => {
            openReference.value = null;
            form.reset();
        },
    });
}
</script>

<template>
    <Head title="Refund queue" />

    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Refund queue</h1>
            <p class="text-muted-foreground mt-1 text-sm">
                Refunds waiting on a person.
            </p>
        </div>

        <Alert>
            <CreditCard class="size-4" aria-hidden="true" />
            <AlertDescription>
                Card refunds are reversed in the Lenco dashboard, not here. Mark
                one done once you have put it through.
            </AlertDescription>
        </Alert>

        <Card>
            <CardContent class="overflow-x-auto p-0">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">Refund</th>
                            <th class="px-4 py-2 font-medium">Order</th>
                            <th class="px-4 py-2 font-medium">Method</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Amount
                            </th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template
                            v-for="refund in refunds"
                            :key="refund.reference"
                        >
                            <tr class="border-t">
                                <td class="px-4 py-2">
                                    <p class="font-mono text-xs">
                                        {{ refund.reference }}
                                    </p>
                                    <Badge variant="secondary" class="mt-1">
                                        {{ refund.statusLabel }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-2">
                                    <a
                                        :href="refund.order.url"
                                        class="font-medium hover:underline"
                                    >
                                        {{ refund.order.number }}
                                    </a>
                                    <p class="text-muted-foreground text-xs">
                                        {{ refund.buyer }}
                                    </p>
                                </td>
                                <td class="px-4 py-2">
                                    <p>{{ refund.methodLabel }}</p>
                                    <p class="text-muted-foreground text-xs">
                                        {{
                                            refund.destination ??
                                            refund.reasonLabel
                                        }}
                                    </p>
                                    <p
                                        v-if="refund.failureReason"
                                        class="text-destructive text-xs"
                                    >
                                        {{ refund.failureReason }}
                                    </p>
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <Money :amount="refund.amountNgwee" />
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="
                                            openReference =
                                                openReference ===
                                                refund.reference
                                                    ? null
                                                    : refund.reference
                                        "
                                    >
                                        Mark paid
                                    </Button>
                                </td>
                            </tr>
                            <tr
                                v-if="openReference === refund.reference"
                                class="bg-muted/30 border-t"
                            >
                                <td colspan="5" class="px-4 py-3">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <Input
                                            v-model="form.note"
                                            class="max-w-md"
                                            placeholder="Reversed in the Lenco dashboard on 11 Sep."
                                        />
                                        <Button
                                            size="sm"
                                            :disabled="form.processing"
                                            @click="complete(refund.reference)"
                                        >
                                            Confirm
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="refunds.length === 0">
                            <td
                                colspan="5"
                                class="text-muted-foreground px-4 py-8 text-center"
                            >
                                Nothing waiting. Every refund has gone out.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
