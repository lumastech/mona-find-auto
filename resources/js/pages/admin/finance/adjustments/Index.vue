<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Plus, ShieldAlert, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import financeAdjustments from '@/routes/admin/finance/adjustments';
import type {
    EntryDirectionValue,
    LabelledOption,
    LedgerAccountOption,
    LedgerAdjustmentRow,
} from '@/types';

/**
 * Manual corrections, under dual control.
 *
 * Drafting posts nothing: the lines sit on the draft until somebody other
 * than their author approves. That is why this form can afford to be
 * forgiving — an unbalanced draft is a validation error in front of the
 * person who can still fix it, rather than a correction to an append-only
 * table.
 *
 * The running total below the lines is the one thing worth watching. The
 * server refuses anything that does not balance; showing the difference here
 * means nobody finds that out by submitting.
 */
const props = defineProps<{
    adjustments: {
        data: LedgerAdjustmentRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: { status: string | null };
    statuses: LabelledOption[];
    accounts: LedgerAccountOption[];
    directions: LabelledOption[];
    canCreate: boolean;
}>();

type DraftLine = {
    account: string;
    direction: EntryDirectionValue;
    amount: string;
    subject_id: string;
    memo: string;
};

const blankLine = (direction: EntryDirectionValue): DraftLine => ({
    account: '',
    direction,
    amount: '',
    subject_id: '',
    memo: '',
});

const creating = ref(false);
const lines = ref<DraftLine[]>([blankLine('debit'), blankLine('credit')]);

const adjustable = computed(() =>
    props.accounts.filter((account) => account.manually_adjustable),
);

const subjectFor = (code: string): 'none' | 'order' | 'seller' =>
    props.accounts.find((account) => account.value === code)?.subject ?? 'none';

const sideTotal = (direction: EntryDirectionValue): number =>
    lines.value
        .filter((line) => line.direction === direction)
        .reduce(
            (sum, line) => sum + Math.round(Number(line.amount || 0) * 100),
            0,
        );

const difference = computed(() => sideTotal('debit') - sideTotal('credit'));

const reset = (): void => {
    lines.value = [blankLine('debit'), blankLine('credit')];
    creating.value = false;
};

const decide = (id: number, action: 'approve' | 'reject'): void => {
    const note = window.prompt(
        action === 'approve'
            ? 'Note for the audit trail (optional)'
            : 'Why is this being rejected? (optional)',
    );

    if (note === null) {
        return;
    }

    router.post(
        action === 'approve'
            ? financeAdjustments.approve(id).url
            : financeAdjustments.reject(id).url,
        { note },
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head title="Ledger adjustments" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Ledger adjustments"
                description="The only route by which a person moves money directly. Finance drafts, an administrator approves, and the two can never be the same person."
            />

            <Button v-if="canCreate" @click="creating = !creating">
                <Plus class="size-4" aria-hidden="true" />
                Draft an adjustment
            </Button>
        </div>

        <Card v-if="creating">
            <CardHeader>
                <CardTitle class="text-base">New adjustment</CardTitle>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="financeAdjustments.store.form()"
                    v-slot="{ errors, processing }"
                    class="space-y-4"
                    @success="reset"
                >
                    <div class="grid gap-2">
                        <Label for="adj-description">Description</Label>
                        <Input
                            id="adj-description"
                            name="description"
                            required
                        />
                        <InputError :message="errors.description" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="adj-reason">
                            Reason — an administrator reads this before
                            approving
                        </Label>
                        <Input id="adj-reason" name="reason" required />
                        <InputError :message="errors.reason" />
                    </div>

                    <div class="space-y-2">
                        <Label>Lines</Label>

                        <div
                            v-for="(line, index) in lines"
                            :key="index"
                            class="grid gap-2 sm:grid-cols-[2fr_1fr_1fr_1fr_auto] sm:items-center"
                        >
                            <select
                                v-model="line.account"
                                :name="`lines[${index}][account]`"
                                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                required
                            >
                                <option value="" disabled>
                                    Choose an account
                                </option>
                                <option
                                    v-for="account in adjustable"
                                    :key="account.value"
                                    :value="account.value"
                                >
                                    {{ account.label }}
                                </option>
                            </select>

                            <select
                                v-model="line.direction"
                                :name="`lines[${index}][direction]`"
                                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            >
                                <option
                                    v-for="direction in directions"
                                    :key="direction.value"
                                    :value="direction.value"
                                >
                                    {{ direction.label }}
                                </option>
                            </select>

                            <Input
                                v-model="line.amount"
                                :name="`lines[${index}][amount]`"
                                inputmode="decimal"
                                placeholder="0.00"
                                required
                            />

                            <Input
                                v-if="subjectFor(line.account) !== 'none'"
                                v-model="line.subject_id"
                                :name="`lines[${index}][subject_id]`"
                                inputmode="numeric"
                                :placeholder="`${subjectFor(line.account)} id`"
                                required
                            />
                            <span v-else class="text-muted-foreground text-xs">
                                Platform-wide
                            </span>

                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :disabled="lines.length <= 2"
                                @click="lines.splice(index, 1)"
                            >
                                <Trash2 class="size-4" aria-hidden="true" />
                                <span class="sr-only">Remove line</span>
                            </Button>
                        </div>

                        <InputError :message="errors.lines" />

                        <div class="flex flex-wrap items-center gap-3 text-sm">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="lines.push(blankLine('debit'))"
                            >
                                Add a line
                            </Button>

                            <span class="text-muted-foreground">
                                Debits <Money :amount="sideTotal('debit')" /> ·
                                credits <Money :amount="sideTotal('credit')" />
                            </span>

                            <Badge
                                :variant="
                                    difference === 0 ? 'outline' : 'destructive'
                                "
                            >
                                {{
                                    difference === 0
                                        ? 'Balanced'
                                        : 'Out by ' +
                                          (Math.abs(difference) / 100).toFixed(
                                              2,
                                          )
                                }}
                            </Badge>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2">
                        <Button type="button" variant="ghost" @click="reset">
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            :disabled="processing || difference !== 0"
                        >
                            <Spinner v-if="processing" />
                            Submit for approval
                        </Button>
                    </div>
                </Form>
            </CardContent>
        </Card>

        <div class="flex flex-wrap gap-2">
            <Button
                :variant="filters.status ? 'outline' : 'secondary'"
                size="sm"
                @click="router.get(financeAdjustments.index().url)"
            >
                All
            </Button>
            <Button
                v-for="status in statuses"
                :key="status.value"
                size="sm"
                :variant="
                    filters.status === status.value ? 'secondary' : 'outline'
                "
                @click="
                    router.get(financeAdjustments.index().url, {
                        status: status.value,
                    })
                "
            >
                {{ status.label }}
            </Button>
        </div>

        <Card
            v-for="adjustment in adjustments.data"
            :key="adjustment.id"
            :class="adjustment.status === 'pending' ? 'border-primary/40' : ''"
        >
            <CardContent class="space-y-3 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold">
                                {{ adjustment.description }}
                            </h3>
                            <Badge
                                :variant="
                                    adjustment.status === 'approved'
                                        ? 'default'
                                        : 'outline'
                                "
                            >
                                {{ adjustment.status_label }}
                            </Badge>
                        </div>
                        <p class="text-muted-foreground mt-1 text-sm">
                            {{ adjustment.reason }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Drafted by {{ adjustment.author ?? 'unknown' }}
                            <template v-if="adjustment.decider">
                                · decided by {{ adjustment.decider }}
                            </template>
                        </p>
                    </div>

                    <div class="text-right">
                        <p class="font-semibold">
                            <Money :amount="adjustment.total_ngwee" />
                        </p>
                        <div
                            v-if="adjustment.can_decide"
                            class="mt-2 flex gap-2"
                        >
                            <Button
                                size="sm"
                                @click="decide(adjustment.id, 'approve')"
                            >
                                Approve
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                @click="decide(adjustment.id, 'reject')"
                            >
                                Reject
                            </Button>
                        </div>
                        <p
                            v-else-if="adjustment.status === 'pending'"
                            class="text-muted-foreground mt-2 flex items-center gap-1 text-xs"
                        >
                            <ShieldAlert class="size-3" aria-hidden="true" />
                            Needs a second pair of eyes
                        </p>
                    </div>
                </div>

                <table class="w-full text-sm">
                    <tbody>
                        <tr
                            v-for="(line, index) in adjustment.lines"
                            :key="index"
                            class="border-t"
                        >
                            <td class="py-1">{{ line.account_label }}</td>
                            <td class="text-muted-foreground py-1">
                                {{
                                    line.subject_id
                                        ? `#${line.subject_id}`
                                        : 'Platform'
                                }}
                            </td>
                            <td class="py-1 text-right">
                                <Money
                                    v-if="line.direction === 'debit'"
                                    :amount="line.amount_ngwee"
                                />
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="py-1 text-right">
                                <Money
                                    v-if="line.direction === 'credit'"
                                    :amount="line.amount_ngwee"
                                />
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <p
            v-if="adjustments.data.length === 0"
            class="text-muted-foreground py-10 text-center text-sm"
        >
            Nothing here. Adjustments are rare by design.
        </p>
    </div>
</template>
