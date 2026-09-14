<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Search } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import Money from '@/components/Money.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import financePolicies from '@/routes/admin/finance/policies';
import type { MonetisationPolicyRow, SellerPolicyRow } from '@/types';

/**
 * Who is on which terms.
 *
 * A separate screen from the policy manager because it answers a different
 * question: the manager asks what terms exist, this asks who is on them. It
 * is the list somebody scans when a payout is queried, which is why the
 * payable and reserve balances sit beside each row.
 */
const props = defineProps<{
    sellers: {
        data: SellerPolicyRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: { search: string | null; policy: string | null };
    policies: MonetisationPolicyRow[];
    defaultPolicy: MonetisationPolicyRow | null;
}>();

const apply = (changes: Record<string, string | null>): void => {
    router.get(
        financePolicies.sellers().url,
        { ...props.filters, ...changes },
        { preserveScroll: true, preserveState: true, replace: true },
    );
};
</script>

<template>
    <Head title="Seller terms" />

    <div class="space-y-6 p-4">
        <Button variant="ghost" size="sm" as-child>
            <Link :href="financePolicies.index().url">
                <ArrowLeft class="size-4" aria-hidden="true" />
                Policies
            </Link>
        </Button>

        <Heading
            title="Seller terms"
            :description="`${sellers.total} sellers. Those without an override trade on ${defaultPolicy?.name ?? 'the platform default'}.`"
        />

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative">
                <Search
                    class="text-muted-foreground absolute top-1/2 left-2 size-4 -translate-y-1/2"
                    aria-hidden="true"
                />
                <Input
                    class="w-72 pl-8"
                    placeholder="Business name"
                    :default-value="filters.search ?? ''"
                    @change="
                        apply({
                            search: ($event.target as HTMLInputElement).value,
                        })
                    "
                />
            </div>

            <Button
                :variant="filters.policy ? 'outline' : 'secondary'"
                size="sm"
                @click="apply({ policy: null })"
            >
                Everyone
            </Button>
            <Button
                size="sm"
                :variant="
                    filters.policy === 'default' ? 'secondary' : 'outline'
                "
                @click="apply({ policy: 'default' })"
            >
                On the default
            </Button>
            <Button
                v-for="policy in policies"
                :key="policy.slug"
                size="sm"
                :variant="
                    filters.policy === policy.slug ? 'secondary' : 'outline'
                "
                @click="apply({ policy: policy.slug })"
            >
                {{ policy.name }}
            </Button>
        </div>

        <Card>
            <CardContent class="overflow-x-auto p-0">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">Seller</th>
                            <th class="px-4 py-2 font-medium">Settlement</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Payable
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Reserve
                            </th>
                            <th class="px-4 py-2 font-medium">Terms</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="seller in sellers.data"
                            :key="seller.id"
                            class="border-t"
                        >
                            <td class="px-4 py-2 font-medium">
                                {{ seller.business_name }}
                            </td>
                            <td class="px-4 py-2">
                                <Badge
                                    :variant="
                                        seller.payment_mode === 'direct'
                                            ? 'default'
                                            : 'outline'
                                    "
                                >
                                    {{ seller.payment_mode_label }}
                                </Badge>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <Money :amount="seller.payable_ngwee" signed />
                            </td>
                            <td class="px-4 py-2 text-right">
                                <Money :amount="seller.reserve_ngwee" />
                            </td>
                            <td class="px-4 py-2">
                                <Form
                                    v-bind="
                                        financePolicies.assign.form(seller.slug)
                                    "
                                    v-slot="{ processing }"
                                    class="flex items-center gap-2"
                                >
                                    <select
                                        name="policy"
                                        class="border-input bg-background h-8 rounded-md border px-2 text-sm"
                                    >
                                        <option
                                            value=""
                                            :selected="seller.on_default"
                                        >
                                            Platform default
                                        </option>
                                        <option
                                            v-for="policy in policies"
                                            :key="policy.slug"
                                            :value="policy.slug"
                                            :selected="
                                                policy.slug ===
                                                seller.policy_slug
                                            "
                                        >
                                            {{ policy.name }}
                                        </option>
                                    </select>
                                    <Input
                                        name="reason"
                                        class="h-8 w-40"
                                        placeholder="Reason"
                                    />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        :disabled="processing"
                                    >
                                        Assign
                                    </Button>
                                </Form>
                            </td>
                        </tr>

                        <tr v-if="sellers.data.length === 0">
                            <td
                                colspan="5"
                                class="text-muted-foreground px-4 py-10 text-center"
                            >
                                No sellers match this filter.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <nav v-if="sellers.links.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="link in sellers.links"
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
