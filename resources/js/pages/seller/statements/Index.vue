<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { FileSpreadsheet, FileText, Info } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import Money from '@/components/Money.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Card, CardContent, CardHeader } from '@/components/ui/card';

/**
 * A seller's own statements and commission invoices.
 *
 * The two sit on one screen because sellers ask about them together: the
 * statement says what was deducted over the month, the invoices are the tax
 * documents for the commission part of it. Split across two screens, a seller
 * finds one and emails support about the other.
 *
 * The note is the single most important thing on the page. Product prices are
 * VAT-inclusive and VAT on the goods is the seller's own to account for;
 * MonaFind's VAT line is charged on its commission alone. A seller who gets
 * that backwards files the wrong number with ZRA.
 */
defineProps<{
    statements: {
        data: {
            id: number;
            period: string;
            periodLabel: string;
            orderCount: number;
            salesNgwee: number;
            deductionsNgwee: number;
            commissionNgwee: number;
            addonFeeNgwee: number;
            referralFeeNgwee: number;
            vatNgwee: number;
            refundsNgwee: number;
            payoutsNgwee: number;
            closingPayableNgwee: number;
            closingReserveNgwee: number;
            pdfUrl: string;
            csvUrl: string;
        }[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    invoices: {
        number: string;
        issuedAt: string;
        commissionNgwee: number;
        vatNgwee: number;
        totalNgwee: number;
        vatRatePercent: string;
        pdfUrl: string;
    }[];
}>();
</script>

<template>
    <Head title="Statements" />

    <div class="space-y-6 p-4">
        <Heading
            title="Statements"
            description="What you sold each month, what MonaFind deducted, and what reached you."
        />

        <Alert>
            <Info class="size-4" />
            <AlertTitle>About VAT</AlertTitle>
            <AlertDescription>
                Your product prices on MonaFindAuto are VAT-inclusive, and VAT
                on the goods you sell is yours to account for to ZRA —
                MonaFindAuto neither collects nor remits it for you. The VAT
                line on your statement is charged on MonaFindAuto&rsquo;s
                commission only, and each commission invoice below itemises it.
            </AlertDescription>
        </Alert>

        <Card>
            <CardHeader>
                <h2 class="font-semibold">Monthly statements</h2>
                <p class="text-muted-foreground text-sm">
                    Closed after the month ends and never changed afterwards, so
                    the copy you download today is the copy you downloaded
                    before.
                </p>
            </CardHeader>
            <CardContent class="space-y-4">
                <div
                    v-for="statement in statements.data"
                    :key="statement.id"
                    class="rounded-lg border p-4"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <div>
                            <h3 class="font-semibold">
                                {{ statement.periodLabel }}
                            </h3>
                            <p class="text-muted-foreground text-xs">
                                {{ statement.orderCount }} order{{
                                    statement.orderCount === 1 ? '' : 's'
                                }}
                            </p>
                        </div>
                        <div class="flex gap-3 text-xs">
                            <a
                                :href="statement.pdfUrl"
                                class="text-primary inline-flex items-center gap-1 underline"
                            >
                                <FileText class="size-3.5" />
                                PDF
                            </a>
                            <a
                                :href="statement.csvUrl"
                                class="text-primary inline-flex items-center gap-1 underline"
                            >
                                <FileSpreadsheet class="size-3.5" />
                                CSV
                            </a>
                        </div>
                    </div>

                    <dl
                        class="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4"
                    >
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Gross sales
                            </dt>
                            <dd class="font-medium">
                                <Money :amount="statement.salesNgwee" />
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Refunded to buyers
                            </dt>
                            <dd>
                                <Money :amount="statement.refundsNgwee" />
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                MonaFind deducted
                            </dt>
                            <dd>
                                <Money :amount="statement.deductionsNgwee" />
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Paid out to you
                            </dt>
                            <dd>
                                <Money :amount="statement.payoutsNgwee" />
                            </dd>
                        </div>
                    </dl>

                    <dl
                        class="text-muted-foreground mt-3 grid gap-3 border-t pt-3 text-xs sm:grid-cols-2 lg:grid-cols-5"
                    >
                        <div>
                            <dt>Commission</dt>
                            <dd>
                                <Money :amount="statement.commissionNgwee" />
                            </dd>
                        </div>
                        <div>
                            <dt>Add-on fee</dt>
                            <dd><Money :amount="statement.addonFeeNgwee" /></dd>
                        </div>
                        <div>
                            <dt>Referral fee</dt>
                            <dd>
                                <Money :amount="statement.referralFeeNgwee" />
                            </dd>
                        </div>
                        <div>
                            <dt>VAT on commission</dt>
                            <dd><Money :amount="statement.vatNgwee" /></dd>
                        </div>
                        <div>
                            <dt>Closing payable</dt>
                            <dd>
                                <Money
                                    :amount="statement.closingPayableNgwee"
                                    signed
                                />
                            </dd>
                        </div>
                    </dl>
                </div>

                <p
                    v-if="statements.data.length === 0"
                    class="text-muted-foreground py-6 text-center text-sm"
                >
                    Your first statement appears in the month after your first
                    sale.
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <h2 class="font-semibold">Commission invoices</h2>
                <p class="text-muted-foreground text-sm">
                    MonaFindAuto&rsquo;s tax invoices to you, for its commission
                    and the VAT on it. They are not invoices for your orders.
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
                                <td class="py-2 pr-4 tabular-nums">
                                    {{ invoice.issuedAt }}
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    <Money :amount="invoice.commissionNgwee" />
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    <Money :amount="invoice.vatNgwee" />
                                    <span class="ml-1 text-xs opacity-70">
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
                                    colspan="6"
                                    class="text-muted-foreground py-6 text-center"
                                >
                                    No commission invoices yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
