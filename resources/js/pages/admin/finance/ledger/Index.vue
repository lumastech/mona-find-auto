<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, Search } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import Money from '@/components/Money.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import financeLedger from '@/routes/admin/finance/ledger';
import type {
    JournalEntryRow,
    LabelledOption,
    LedgerAccountSummary,
} from '@/types';

/**
 * The ledger browser.
 *
 * Read-only, and there is no other kind — entries are append-only, so there
 * is nothing here to edit and deliberately no button suggesting otherwise.
 * The one route by which a person moves money is a manual adjustment, which
 * has its own screen and needs two people.
 *
 * The balances across the top are the cached figures. `discrepancies` is how
 * many of them disagree with the lines beneath them, and it should always be
 * zero; it is shown rather than logged because a drifted balance is exactly
 * the kind of thing that goes unnoticed until it is expensive.
 */
const props = defineProps<{
    entries: {
        data: JournalEntryRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: {
        account: string | null;
        recipe: string | null;
        search: string | null;
    };
    accounts: LedgerAccountSummary[];
    recipes: LabelledOption[];
    discrepancies: number;
}>();

const apply = (changes: Record<string, string | null>): void => {
    router.get(
        financeLedger.index().url,
        { ...props.filters, ...changes },
        { preserveScroll: true, preserveState: true, replace: true },
    );
};

/** Assets and expenses read left-to-right; liabilities and revenue do not. */
const groupLabel: Record<string, string> = {
    asset: 'Assets',
    liability: 'Held for others',
    revenue: 'Revenue',
    expense: 'Costs',
};

const grouped = (type: string): LedgerAccountSummary[] =>
    props.accounts.filter((account) => account.type === type);
</script>

<template>
    <Head title="Ledger" />

    <div class="space-y-6 p-4">
        <Heading
            title="Ledger"
            description="Every movement of money on the platform, in the order it happened. Entries cannot be edited — corrections are posted as adjustments."
        />

        <div
            v-if="discrepancies > 0"
            class="border-destructive/40 bg-destructive/10 text-destructive flex items-start gap-3 rounded-lg border p-4 text-sm"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <p>
                {{ discrepancies }} cached
                {{
                    discrepancies === 1
                        ? 'balance disagrees'
                        : 'balances disagree'
                }}
                with the journal lines beneath them. The lines are the truth;
                the cache needs rebuilding.
            </p>
        </div>

        <section
            v-for="type in ['asset', 'liability', 'revenue', 'expense']"
            :key="type"
            class="space-y-2"
        >
            <h2 class="text-muted-foreground text-xs font-medium uppercase">
                {{ groupLabel[type] }}
            </h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Card
                    v-for="account in grouped(type)"
                    :key="account.value"
                    class="cursor-pointer transition-colors"
                    :class="
                        filters.account === account.value
                            ? 'border-primary'
                            : 'hover:border-muted-foreground/40'
                    "
                    @click="
                        apply({
                            account:
                                filters.account === account.value
                                    ? null
                                    : account.value,
                        })
                    "
                >
                    <CardContent class="space-y-1 p-4">
                        <p class="text-muted-foreground text-xs">
                            {{ account.label }}
                        </p>
                        <p class="text-lg font-semibold">
                            <Money :amount="account.balance_ngwee" />
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative">
                <Search
                    class="text-muted-foreground absolute top-1/2 left-2 size-4 -translate-y-1/2"
                    aria-hidden="true"
                />
                <Input
                    class="w-72 pl-8"
                    placeholder="Description, entry id or event key"
                    :default-value="filters.search ?? ''"
                    @change="
                        apply({
                            search: ($event.target as HTMLInputElement).value,
                        })
                    "
                />
            </div>

            <Button
                :variant="filters.recipe ? 'outline' : 'secondary'"
                size="sm"
                @click="apply({ recipe: null })"
            >
                Every movement
            </Button>
            <Button
                v-for="recipe in recipes"
                :key="recipe.value"
                size="sm"
                :variant="
                    filters.recipe === recipe.value ? 'secondary' : 'outline'
                "
                @click="apply({ recipe: recipe.value })"
            >
                {{ recipe.label }}
            </Button>
        </div>

        <Card>
            <CardContent class="overflow-x-auto p-0">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">Posted</th>
                            <th class="px-4 py-2 font-medium">Movement</th>
                            <th class="px-4 py-2 font-medium">About</th>
                            <th class="px-4 py-2 font-medium">By</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Amount
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="entry in entries.data"
                            :key="entry.uuid"
                            class="border-t align-top"
                        >
                            <td class="text-muted-foreground px-4 py-2">
                                {{
                                    new Date(entry.posted_at).toLocaleString(
                                        'en-GB',
                                    )
                                }}
                            </td>
                            <td class="px-4 py-2">
                                <Link
                                    :href="financeLedger.show(entry.uuid).url"
                                    class="font-medium hover:underline"
                                >
                                    {{ entry.description }}
                                </Link>
                                <div class="mt-1">
                                    <Badge variant="outline">
                                        {{ entry.recipe_label }}
                                    </Badge>
                                </div>
                            </td>
                            <td class="px-4 py-2">
                                {{ entry.reference_label ?? '—' }}
                            </td>
                            <td class="text-muted-foreground px-4 py-2">
                                {{ entry.actor_label }}
                            </td>
                            <td class="px-4 py-2 text-right font-medium">
                                <Money :amount="entry.total_ngwee" />
                            </td>
                        </tr>

                        <tr v-if="entries.data.length === 0">
                            <td
                                colspan="5"
                                class="text-muted-foreground px-4 py-10 text-center"
                            >
                                Nothing has moved that matches this filter.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <nav v-if="entries.links.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="link in entries.links"
                :key="link.label"
                :href="link.url ?? '#'"
                class="rounded border px-3 py-1 text-sm"
                :class="{
                    'bg-primary text-primary-foreground': link.active,
                    'pointer-events-none opacity-50': !link.url,
                }"
                v-html="link.label"
            />
        </nav>
    </div>
</template>
