<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

/**
 * One night's exceptions.
 *
 * Resolving is an annotation, never a deletion — nothing on this screen can
 * make an exception disappear, only explain it. An exception that could be
 * removed is an exception nobody has to account for.
 */
const props = defineProps<{
    run: {
        id: number;
        date: string;
        status: string;
        statusLabel: string;
        collectionsChecked: number;
        settlementsChecked: number;
        transactionsChecked: number;
        gatewayTotalNgwee: number;
        ledgerTotalNgwee: number;
        varianceNgwee: number;
        failureReason: string | null;
    };
    exceptions: Array<{
        id: number;
        type: string;
        typeLabel: string;
        severity: string;
        reference: string | null;
        detail: string;
        gatewayAmountNgwee: number | null;
        ledgerAmountNgwee: number | null;
        varianceNgwee: number | null;
        isResolved: boolean;
        resolutionNote: string | null;
        resolvedBy: string | null;
    }>;
}>();

const openId = ref<number | null>(null);
const form = useForm({ note: '' });

function resolve(id: number): void {
    form.post(`/admin/reconciliation/exceptions/${id}/resolve`, {
        preserveScroll: true,
        onSuccess: () => {
            openId.value = null;
            form.reset();
        },
    });
}
</script>

<template>
    <Head :title="`Reconciliation ${run.date}`" />

    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ run.date }}
            </h1>
            <p class="text-muted-foreground mt-1 text-sm">
                {{ run.collectionsChecked }} collections ·
                {{ run.settlementsChecked }} settlements ·
                {{ run.transactionsChecked }} transactions
            </p>
        </div>

        <Alert v-if="run.failureReason" variant="destructive">
            <AlertTitle>This run could not finish</AlertTitle>
            <AlertDescription>{{ run.failureReason }}</AlertDescription>
        </Alert>

        <div class="grid gap-4 sm:grid-cols-3">
            <Card>
                <CardHeader class="pb-2">
                    <span class="text-muted-foreground text-sm"
                        >Gateway collected</span
                    >
                </CardHeader>
                <CardContent>
                    <Money
                        :amount="run.gatewayTotalNgwee"
                        class="text-2xl font-semibold"
                    />
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <span class="text-muted-foreground text-sm"
                        >Ledger cash in</span
                    >
                </CardHeader>
                <CardContent>
                    <Money
                        :amount="run.ledgerTotalNgwee"
                        class="text-2xl font-semibold"
                    />
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <span class="text-muted-foreground text-sm">Variance</span>
                </CardHeader>
                <CardContent>
                    <Money
                        :amount="run.varianceNgwee"
                        signed
                        class="text-2xl font-semibold"
                    />
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <h2 class="font-medium">Exceptions</h2>
            </CardHeader>
            <CardContent class="space-y-3">
                <p
                    v-if="exceptions.length === 0"
                    class="text-muted-foreground py-6 text-center text-sm"
                >
                    Nothing to explain. Both sides agreed.
                </p>

                <div
                    v-for="exception in exceptions"
                    :key="exception.id"
                    class="rounded-md border p-3"
                    :class="exception.isResolved ? 'opacity-60' : ''"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <div class="flex items-center gap-2">
                                <Badge
                                    :variant="
                                        exception.severity === 'critical'
                                            ? 'destructive'
                                            : 'secondary'
                                    "
                                >
                                    {{ exception.typeLabel }}
                                </Badge>
                                <span
                                    v-if="exception.reference"
                                    class="font-mono text-xs"
                                >
                                    {{ exception.reference }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm">{{ exception.detail }}</p>
                            <p
                                v-if="
                                    exception.gatewayAmountNgwee !== null ||
                                    exception.ledgerAmountNgwee !== null
                                "
                                class="text-muted-foreground mt-1 text-xs"
                            >
                                Gateway
                                <Money
                                    :amount="exception.gatewayAmountNgwee ?? 0"
                                />
                                · Ledger
                                <Money
                                    :amount="exception.ledgerAmountNgwee ?? 0"
                                />
                            </p>
                            <p
                                v-if="exception.isResolved"
                                class="text-muted-foreground mt-2 text-xs"
                            >
                                Resolved by {{ exception.resolvedBy }} —
                                {{ exception.resolutionNote }}
                            </p>
                        </div>

                        <Button
                            v-if="!exception.isResolved"
                            size="sm"
                            variant="outline"
                            @click="
                                openId =
                                    openId === exception.id
                                        ? null
                                        : exception.id
                            "
                        >
                            Resolve
                        </Button>
                    </div>

                    <div
                        v-if="openId === exception.id"
                        class="mt-3 flex flex-wrap items-center gap-2"
                    >
                        <Input
                            v-model="form.note"
                            class="max-w-md"
                            placeholder="What happened, and what was done about it."
                        />
                        <Button
                            size="sm"
                            :disabled="form.processing"
                            @click="resolve(exception.id)"
                        >
                            Save
                        </Button>
                        <InputError :message="form.errors.note" />
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
