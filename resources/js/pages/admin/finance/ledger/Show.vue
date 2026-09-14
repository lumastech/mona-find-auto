<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import Money from '@/components/Money.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import financeLedger from '@/routes/admin/finance/ledger';
import type { JournalEntryRow } from '@/types';

/**
 * One entry, with the lines that make it up.
 *
 * The debit and credit totals are shown side by side because they are the
 * whole claim the entry makes: they are equal, or the entry would not exist.
 * The event key is shown too — it is what stopped this movement being posted
 * twice, and it is the first thing anybody checks when they suspect it was.
 */
const props = defineProps<{ entry: JournalEntryRow }>();

const lines = computed(() => props.entry.lines ?? []);

const total = (direction: 'debit' | 'credit'): number =>
    lines.value
        .filter((line) => line.direction === direction)
        .reduce((sum, line) => sum + line.amount_ngwee, 0);
</script>

<template>
    <Head :title="entry.description" />

    <div class="space-y-6 p-4">
        <Button variant="ghost" size="sm" as-child>
            <Link :href="financeLedger.index().url">
                <ArrowLeft class="size-4" aria-hidden="true" />
                Ledger
            </Link>
        </Button>

        <Heading
            :title="entry.description"
            :description="`${entry.recipe_label} · posted ${new Date(entry.posted_at).toLocaleString('en-GB')} by ${entry.actor_label}`"
        />

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Lines</CardTitle>
            </CardHeader>
            <CardContent class="overflow-x-auto p-0">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">Account</th>
                            <th class="px-4 py-2 font-medium">For</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Debit
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Credit
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(line, index) in lines"
                            :key="index"
                            class="border-t"
                        >
                            <td class="px-4 py-2">
                                <span class="font-medium">
                                    {{ line.account_label }}
                                </span>
                                <p
                                    v-if="line.memo"
                                    class="text-muted-foreground text-xs"
                                >
                                    {{ line.memo }}
                                </p>
                            </td>
                            <td class="text-muted-foreground px-4 py-2">
                                <template v-if="line.subject_label">
                                    {{ line.subject_type }}
                                    {{ line.subject_label }}
                                </template>
                                <template v-else>Platform</template>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <Money
                                    v-if="line.direction === 'debit'"
                                    :amount="line.amount_ngwee"
                                />
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-4 py-2 text-right">
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
                    <tfoot class="bg-muted/30 font-medium">
                        <tr class="border-t">
                            <td class="px-4 py-2" colspan="2">Totals</td>
                            <td class="px-4 py-2 text-right">
                                <Money :amount="total('debit')" />
                            </td>
                            <td class="px-4 py-2 text-right">
                                <Money :amount="total('credit')" />
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Provenance</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-muted-foreground">About</span>
                    <Badge variant="outline">
                        {{ entry.reference_label ?? 'Nothing in particular' }}
                    </Badge>
                </div>

                <div>
                    <p class="text-muted-foreground">Business event</p>
                    <code class="text-xs break-all">
                        {{ entry.idempotency_key }}
                    </code>
                    <p class="text-muted-foreground mt-1 text-xs">
                        Named so that a webhook delivered twice, or a retried
                        job, posts this movement exactly once.
                    </p>
                </div>

                <div>
                    <p class="text-muted-foreground">Entry</p>
                    <code class="text-xs break-all">{{ entry.uuid }}</code>
                </div>

                <div v-if="entry.context">
                    <p class="text-muted-foreground">Context</p>
                    <pre
                        class="bg-muted mt-1 overflow-x-auto rounded p-3 text-xs"
                        >{{ JSON.stringify(entry.context, null, 2) }}</pre>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
