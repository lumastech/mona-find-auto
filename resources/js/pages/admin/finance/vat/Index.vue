<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Info, Trash2 } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import vat from '@/routes/admin/finance/vat';

/**
 * The VAT rate MonaFind charges on its commission, and when each one started.
 *
 * The note at the top is not reassurance for its own sake. An administrator
 * who believes this screen can reach settled orders will be afraid to correct
 * a typo, and a wrong future rate left in place because nobody dared touch it
 * is the more expensive outcome. The rate is snapshotted onto an order at
 * payment time; nothing here can reach one.
 *
 * A rate that has taken effect offers no withdraw control — not a disabled
 * one. `isWithdrawable` comes from the same method the server enforces, so
 * the screen and the server cannot disagree about what may still be removed.
 */
defineProps<{
    rates: {
        id: number;
        ratePercent: string;
        effectiveFrom: string;
        note: string | null;
        author: string | null;
        isCurrent: boolean;
        isScheduled: boolean;
        isWithdrawable: boolean;
    }[];
    currentPercent: string;
    canManage: boolean;
}>();

const form = useForm({
    rate_percent: '',
    effective_from: '',
    note: '',
});

const submit = (): void => {
    form.post(vat.store().url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};

const withdraw = (id: number): void => {
    useForm({}).delete(vat.destroy(id).url, { preserveScroll: true });
};
</script>

<template>
    <Head title="VAT on commission" />

    <div class="space-y-6 p-4">
        <Heading
            title="VAT on commission"
            description="The rate MonaFind charges on its own fee — the one figure on the platform that is not MonaFind’s to choose."
        />

        <Alert>
            <Info class="size-4" />
            <AlertTitle> Changing this affects future orders only </AlertTitle>
            <AlertDescription>
                The rate in force is copied onto an order when its payment
                lands, and every invoice, statement and refund figure is derived
                from that copy. Nothing on this screen can reach an order that
                has already been paid, so a mistake here is recoverable —
                correct it rather than working around it. Product prices remain
                VAT-inclusive throughout, and VAT on the goods stays the
                seller’s own responsibility.
            </AlertDescription>
        </Alert>

        <Card>
            <CardContent class="flex flex-wrap items-center gap-6 pt-6">
                <div>
                    <p class="text-muted-foreground text-xs">In force today</p>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ currentPercent }}%
                    </p>
                </div>
                <p class="text-muted-foreground max-w-lg text-sm">
                    Charged on MonaFind’s commission and nothing else. It is
                    collected from the seller and owed onward to ZRA, so it
                    never counts as platform revenue.
                </p>
            </CardContent>
        </Card>

        <Card v-if="canManage">
            <CardHeader>
                <h2 class="font-semibold">Schedule a rate</h2>
                <p class="text-muted-foreground text-sm">
                    Enter it the day the statutory instrument is published; it
                    starts applying on its own date. A date already on the
                    schedule is amended rather than duplicated.
                </p>
            </CardHeader>
            <CardContent>
                <form
                    class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
                    @submit.prevent="submit"
                >
                    <div class="space-y-1.5">
                        <Label for="rate_percent">Rate (%)</Label>
                        <Input
                            id="rate_percent"
                            v-model="form.rate_percent"
                            inputmode="decimal"
                            placeholder="16.00"
                        />
                        <InputError :message="form.errors.rate_percent" />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="effective_from">Effective from</Label>
                        <Input
                            id="effective_from"
                            v-model="form.effective_from"
                            type="date"
                        />
                        <InputError :message="form.errors.effective_from" />
                    </div>
                    <div class="space-y-1.5 sm:col-span-2">
                        <Label for="note">Note</Label>
                        <Input
                            id="note"
                            v-model="form.note"
                            placeholder="Statutory instrument or reason"
                        />
                        <InputError :message="form.errors.note" />
                    </div>
                    <div class="sm:col-span-2 lg:col-span-4">
                        <Button type="submit" :disabled="form.processing">
                            Add to the schedule
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <h2 class="font-semibold">The schedule</h2>
            </CardHeader>
            <CardContent>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead
                            class="text-muted-foreground border-b text-left text-xs"
                        >
                            <tr>
                                <th class="py-2 pr-4 font-medium">
                                    Effective from
                                </th>
                                <th class="py-2 pr-4 font-medium">Rate</th>
                                <th class="py-2 pr-4 font-medium">Note</th>
                                <th class="py-2 pr-4 font-medium">Set by</th>
                                <th class="py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="rate in rates"
                                :key="rate.id"
                                class="border-b last:border-0"
                            >
                                <td class="py-2 pr-4 tabular-nums">
                                    {{ rate.effectiveFrom }}
                                </td>
                                <td class="py-2 pr-4">
                                    <span class="font-medium tabular-nums">
                                        {{ rate.ratePercent }}%
                                    </span>
                                    <Badge
                                        v-if="rate.isCurrent"
                                        class="ml-2"
                                        variant="default"
                                    >
                                        In force
                                    </Badge>
                                    <Badge
                                        v-else-if="rate.isScheduled"
                                        class="ml-2"
                                        variant="secondary"
                                    >
                                        Scheduled
                                    </Badge>
                                </td>
                                <td class="text-muted-foreground py-2 pr-4">
                                    {{ rate.note ?? '—' }}
                                </td>
                                <td class="text-muted-foreground py-2 pr-4">
                                    {{ rate.author ?? 'System' }}
                                </td>
                                <td class="py-2 text-right">
                                    <!--
                                        Absent, not disabled, for a rate that
                                        has taken effect: it may have priced a
                                        real order.
                                    -->
                                    <Button
                                        v-if="canManage && rate.isWithdrawable"
                                        variant="ghost"
                                        size="sm"
                                        @click="withdraw(rate.id)"
                                    >
                                        <Trash2 class="size-4" />
                                        Withdraw
                                    </Button>
                                </td>
                            </tr>
                            <tr v-if="rates.length === 0">
                                <td
                                    colspan="5"
                                    class="text-muted-foreground py-6 text-center"
                                >
                                    No rates on the schedule yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
