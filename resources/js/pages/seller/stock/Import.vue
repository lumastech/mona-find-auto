<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CircleCheck, TriangleAlert } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import Money from '@/components/Money.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import sellerStock from '@/routes/seller/stock';
import type { StockImportBatch } from '@/types';

/**
 * The report a seller reads before a bulk upload touches anything.
 *
 * Only the rejected rows are listed. A seller does not read four hundred
 * lines of "this was fine" — they read the six that were not, and they need
 * the line number to find them in their own spreadsheet.
 *
 * Applying is deliberately not the default action of the page: the good rows
 * go in when the seller says so, and walking away leaves their shelves
 * exactly as they were.
 */
const props = defineProps<{ batch: StockImportBatch }>();

const applyForm = useForm({});
const discardForm = useForm({});

const apply = () =>
    applyForm.post(sellerStock.imports.apply(props.batch.id).url);

const discard = () =>
    discardForm.delete(sellerStock.imports.destroy(props.batch.id).url);
</script>

<template>
    <Head title="Stock upload" />

    <div class="space-y-6 p-4">
        <Button variant="ghost" size="sm" as-child>
            <Link :href="sellerStock.index().url">
                <ArrowLeft class="size-4" aria-hidden="true" />
                Back to stock
            </Link>
        </Button>

        <Heading
            :title="batch.original_filename"
            description="What MonaFind found in your file. Nothing has been changed yet."
        />

        <Alert v-if="batch.failure_reason" variant="destructive">
            <TriangleAlert class="size-4" aria-hidden="true" />
            <AlertTitle>This file could not be read</AlertTitle>
            <AlertDescription>{{ batch.failure_reason }}</AlertDescription>
        </Alert>

        <div v-else class="grid gap-3 sm:grid-cols-3">
            <Card>
                <CardContent class="p-4">
                    <p class="text-muted-foreground text-xs">Rows read</p>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ batch.total_rows }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="p-4">
                    <p class="text-muted-foreground text-xs">Ready to apply</p>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ batch.valid_rows }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="p-4">
                    <p class="text-muted-foreground text-xs">Need fixing</p>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ batch.invalid_rows }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <Alert v-if="batch.status.value === 'applied'">
            <CircleCheck class="size-4" aria-hidden="true" />
            <AlertTitle>Applied</AlertTitle>
            <AlertDescription>
                {{ batch.applied_rows }} rows are on your shelves.
            </AlertDescription>
        </Alert>

        <section v-if="batch.problems.length" class="space-y-3">
            <h2 class="font-medium">
                Rows that will not be applied
                <Badge variant="destructive" class="ml-2">
                    {{ batch.problems.length }}
                </Badge>
            </h2>

            <p class="text-muted-foreground text-sm">
                Line numbers match your spreadsheet, counting the heading row.
                Fix these and upload again — the good rows can go in now.
            </p>

            <div class="overflow-x-auto rounded border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="p-3 font-medium">Line</th>
                            <th class="p-3 font-medium">SKU</th>
                            <th class="p-3 font-medium">Quantity</th>
                            <th class="p-3 font-medium">Price</th>
                            <th class="p-3 font-medium">What is wrong</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="row in batch.problems" :key="row.line">
                            <td class="p-3 tabular-nums">{{ row.line }}</td>
                            <td class="p-3">{{ row.sku ?? '—' }}</td>
                            <td class="p-3 tabular-nums">
                                {{ row.quantity ?? '—' }}
                            </td>
                            <td class="p-3">
                                <Money
                                    v-if="row.price_ngwee !== null"
                                    :amount="row.price_ngwee"
                                />
                                <span v-else>—</span>
                            </td>
                            <td class="text-destructive p-3">
                                <p
                                    v-for="error in row.errors"
                                    :key="error"
                                    class="not-first:mt-1"
                                >
                                    {{ error }}
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div v-if="batch.status.applicable" class="flex flex-wrap gap-2">
            <Button
                type="button"
                :disabled="applyForm.processing || batch.valid_rows === 0"
                @click="apply"
            >
                Apply {{ batch.valid_rows }} rows
            </Button>
            <Button
                type="button"
                variant="outline"
                :disabled="discardForm.processing"
                @click="discard"
            >
                Discard this upload
            </Button>
        </div>
    </div>
</template>
