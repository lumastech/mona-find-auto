<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { BadgeCheck, Lock, Trash2 } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import StepPayout from '@/components/seller/wizard/StepPayout.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import sellerRoutes from '@/routes/seller';
import type { BankOption, LabelledOption, PayoutAccount } from '@/types';

/**
 * Where a seller's money is sent.
 *
 * The add-account form is the same component the sign-up wizard uses: there
 * is one right way to check an account with the gateway, and two copies of it
 * would eventually disagree.
 */
defineProps<{
    accounts: PayoutAccount[];
    methods: LabelledOption[];
    banks: BankOption[];
}>();
</script>

<template>
    <Head title="Payout accounts" />

    <div class="space-y-6 p-4">
        <Heading
            title="Payout accounts"
            description="Where MonaFind sends your money. We confirm every account with your bank or wallet provider before saving it."
        />

        <Card v-if="accounts.length">
            <CardHeader>
                <CardTitle class="text-base">Accounts on file</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <div
                    v-for="account in accounts"
                    :key="account.id"
                    class="flex flex-wrap items-center gap-3 rounded-lg border p-4"
                >
                    <div class="min-w-0 flex-1">
                        <p
                            class="flex flex-wrap items-center gap-2 font-medium"
                        >
                            {{ account.display_name }}
                            <Badge
                                v-if="account.is_default"
                                variant="secondary"
                            >
                                Default
                            </Badge>
                            <Badge v-if="account.resolved" variant="outline">
                                <BadgeCheck aria-hidden="true" />
                                Confirmed
                            </Badge>
                        </p>
                        <p class="text-muted-foreground text-sm">
                            {{ account.bank_name ?? account.network_label }} ·
                            your provider calls this account
                            <span class="text-foreground">
                                {{ account.resolved_name }}
                            </span>
                        </p>
                    </div>

                    <Link
                        v-if="!account.is_default"
                        :href="sellerRoutes.payoutAccounts.default(account.id)"
                        method="put"
                        as="button"
                        class="text-sm underline-offset-4 hover:underline"
                    >
                        Make default
                    </Link>

                    <Link
                        :href="sellerRoutes.payoutAccounts.destroy(account.id)"
                        method="delete"
                        as="button"
                        class="text-muted-foreground hover:text-destructive"
                        :aria-label="`Remove ${account.display_name}`"
                    >
                        <Trash2 class="size-4" aria-hidden="true" />
                    </Link>
                </div>

                <p
                    class="text-muted-foreground flex items-start gap-2 pt-2 text-sm"
                >
                    <Lock class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>
                        Account numbers are encrypted at rest and never shown in
                        full again, even to you.
                    </span>
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Add an account</CardTitle>
            </CardHeader>
            <CardContent>
                <StepPayout
                    :accounts="[]"
                    :methods="methods"
                    :banks="banks"
                    :can-continue="false"
                />
            </CardContent>
        </Card>
    </div>
</template>
