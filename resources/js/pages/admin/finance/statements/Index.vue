<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Download, FileText } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import financeStatements from '@/routes/admin/finance/statements';

/**
 * Seller statements and the commission invoices behind them.
 *
 * Both are CLOSED records. Nothing on this screen recomputes a figure; the
 * downloads render the row that was written when the month closed, so the
 * copy a seller already has and the copy finance pulls up are the same
 * document. Rebuilding a closed month is deliberately not on this screen —
 * it is a platform-administrator act, audited, because a seller may be
 * holding the superseded copy.
 */
const props = defineProps<{
    statements: {
        data: {
            id: number;
            seller: string;
            period: string;
            periodLabel: string;
            orderCount: number;
            salesNgwee: number;
            commissionNgwee: number;
            vatNgwee: number;
            payoutsNgwee: number;
            closingPayableNgwee: number;
            pdfUrl: string;
            csvUrl: string;
        }[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: { period: string | null; search: string | null };
    invoices: {
        number: string;
        seller: string;
        issuedAt: string;
        commissionNgwee: number;
        vatNgwee: number;
        totalNgwee: number;
        vatRatePercent: string;
        pdfUrl: string;
    }[];
}>();

const closing = useForm({ month: '' });

const close = (): void => {
    closing.post(financeStatements.store().url, {
        preserveScroll: true,
        onSuccess: () => closing.reset(),
    });
};

const apply = (changes: Record<string, string | null>): void => {
    router.get(
        financeStatements.index().url,
        { ...props.filters, ...changes },
        { preserveScroll: true, preserveState: true, replace: true },
    );
};
</script>

<template>
    <Head title="Statements" />

    <div class="space-y-6 p-4">
        <Heading
            title="Statements and invoices"
            description="What each seller was paid, what MonaFind deducted, and the numbered invoices for the commission."
        />

        <Card>
            <CardHeader>
                <h2 class="font-semibold">Close a month</h2>
                <p class="text-muted-foreground text-sm">
                    Runs automatically on the fourth of each month. Use this to
                    finish a run that failed part-way — a month already closed
                    is left exactly as it is.
                </p>
            </CardHeader>
            <CardContent>
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="close"
                >
                    <div class="space-y-1.5">
                        <Label for="month">Month</Label>
                        <Input
                            id="month"
                            v-model="closing.month"
                            type="month"
                        />
                        <InputError :message="closing.errors.month" />
                    </div>
                    <Button type="submit" :disabled="closing.processing">
                        Close the month
                    </Button>
                </form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader class="gap-3">
                <h2 class="font-semibold">
                    Statements
                    <span class="text-muted-foreground font-normal">
                        ({{ statements.total }})
                    </span>
                </h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Input
                        type="month"
                        :model-value="filters.period ?? ''"
                        @change="
                            apply({
                                period:
                                    ($event.target as HTMLInputElement).value ||
                                    null,
                            })
                        "
                    />
                    <Input
                        placeholder="Search sellers"
                        :model-value="filters.search ?? ''"
                        @change="
                            apply({
                                search:
                                    ($event.target as HTMLInputElement).value ||
                                    null,
                            })
                        "
                    />
                </div>
            </CardHeader>
            <CardContent>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead
                            class="text-muted-foreground border-b text-left text-xs"
                        >
                            <tr>
                                <th class="py-2 pr-4 font-medium">Seller</th>
                                <th class="py-2 pr-4 font-medium">Period</th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Orders
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Sales
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Commission
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    VAT
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Paid out
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Closing payable
                                </th>
                                <th class="py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in statements.data"
                                :key="row.id"
                                class="border-b last:border-0"
                            >
                                <td class="py-2 pr-4">{{ row.seller }}</td>
                                <td class="py-2 pr-4">
                                    {{ row.periodLabel }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ row.orderCount }}
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    <Money :amount="row.salesNgwee" />
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    <Money :amount="row.commissionNgwee" />
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    <Money :amount="row.vatNgwee" />
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    <Money :amount="row.payoutsNgwee" />
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    <Money
                                        :amount="row.closingPayableNgwee"
                                        signed
                                    />
                                </td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    <a
                                        :href="row.pdfUrl"
                                        class="text-primary mr-3 text-xs underline"
                                    >
                                        PDF
                                    </a>
                                    <a
                                        :href="row.csvUrl"
                                        class="text-primary text-xs underline"
                                    >
                                        CSV
                                    </a>
                                </td>
                            </tr>
                            <tr v-if="statements.data.length === 0">
                                <td
                                    colspan="9"
                                    class="text-muted-foreground py-6 text-center"
                                >
                                    No statements for this filter.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <h2 class="font-semibold">Recent commission invoices</h2>
                <p class="text-muted-foreground text-sm">
                    MonaFind invoices the commission and the VAT on it — never
                    the order. Numbering is gapless within each year.
                </p>
            </CardHeader>
            <CardContent>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead
                            class="text-muted-foreground border-b text-left text-xs"
                        >
                            <tr>
                                <th class="py-2 pr-4 font-medium">Number</th>
                                <th class="py-2 pr-4 font-medium">Seller</th>
                                <th class="py-2 pr-4 font-medium">Issued</th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Commission
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    VAT
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Total
                                </th>
                                <th class="py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="invoice in invoices"
                                :key="invoice.number"
                                class="border-b last:border-0"
                            >
                                <td class="py-2 pr-4 font-medium">
                                    {{ invoice.number }}
                                </td>
                                <td class="py-2 pr-4">{{ invoice.seller }}</td>
                                <td class="py-2 pr-4 tabular-nums">
                                    {{ invoice.issuedAt }}
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    <Money :amount="invoice.commissionNgwee" />
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    <Money :amount="invoice.vatNgwee" />
                                    <span
                                        class="text-muted-foreground ml-1 text-xs"
                                    >
                                        @ {{ invoice.vatRatePercent }}%
                                    </span>
                                </td>
                                <td class="py-2 pr-4 text-right font-medium">
                                    <Money :amount="invoice.totalNgwee" />
                                </td>
                                <td class="py-2 text-right">
                                    <a
                                        :href="invoice.pdfUrl"
                                        class="text-primary text-xs underline"
                                    >
                                        PDF
                                    </a>
                                </td>
                            </tr>
                            <tr v-if="invoices.length === 0">
                                <td
                                    colspan="7"
                                    class="text-muted-foreground py-6 text-center"
                                >
                                    No commission has been recognised yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
