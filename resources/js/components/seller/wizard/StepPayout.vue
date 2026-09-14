<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { BadgeCheck, Lock, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import seller from '@/routes/seller';
import sellers from '@/routes/sellers';
import type {
    BankOption,
    LabelledOption,
    PayoutAccount,
    PayoutMethodValue,
} from '@/types';

/**
 * Step four: where payouts land.
 *
 * The account is checked with the bank or wallet provider before it is saved,
 * and the name that comes back is the bank's — not what the seller typed. A
 * mismatch is worth seeing now rather than when a payout bounces.
 */
defineProps<{
    accounts: PayoutAccount[];
    methods: LabelledOption[];
    banks: BankOption[];
    canContinue: boolean;
}>();

const method = ref<PayoutMethodValue>('bank');
</script>

<template>
    <div class="space-y-8">
        <div v-if="accounts.length" class="space-y-3">
            <h2 class="text-sm font-medium">Accounts on file</h2>

            <div
                v-for="account in accounts"
                :key="account.id"
                class="flex flex-wrap items-center gap-3 rounded-lg border p-4"
            >
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-2 font-medium">
                        {{ account.display_name }}
                        <Badge v-if="account.is_default" variant="secondary">
                            Default
                        </Badge>
                        <Badge v-if="account.resolved" variant="outline">
                            <BadgeCheck aria-hidden="true" />
                            Confirmed
                        </Badge>
                    </p>
                    <p class="text-muted-foreground text-sm">
                        Your bank calls this account
                        <span class="text-foreground">
                            {{ account.resolved_name }}
                        </span>
                    </p>
                </div>

                <Link
                    :href="seller.payoutAccounts.destroy(account.id)"
                    method="delete"
                    as="button"
                    class="text-muted-foreground hover:text-destructive"
                    aria-label="Remove this account"
                >
                    <Trash2 class="size-4" aria-hidden="true" />
                </Link>
            </div>
        </div>

        <Form
            v-bind="seller.payoutAccounts.store.form()"
            v-slot="{ errors, processing }"
            class="space-y-6"
        >
            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">
                    How would you like to be paid?
                </legend>

                <div class="flex flex-wrap gap-3">
                    <label
                        v-for="option in methods"
                        :key="option.value"
                        class="flex cursor-pointer items-center gap-2 rounded-lg border px-4 py-2"
                        :class="
                            method === option.value
                                ? 'border-primary bg-primary/5'
                                : ''
                        "
                    >
                        <input
                            v-model="method"
                            type="radio"
                            name="method"
                            :value="option.value"
                            class="size-4"
                        />
                        {{ option.label }}
                    </label>
                </div>
                <InputError :message="errors.method" />
            </fieldset>

            <div class="grid gap-2">
                <Label for="label">
                    Name this account
                    <span class="text-muted-foreground font-normal">
                        (optional)
                    </span>
                </Label>
                <Input
                    id="label"
                    name="label"
                    placeholder="e.g. Main account"
                />
            </div>

            <template v-if="method === 'bank'">
                <div class="grid gap-6 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="beneficiary_name">Account holder</Label>
                        <Input
                            id="beneficiary_name"
                            name="beneficiary_name"
                            required
                        />
                        <InputError :message="errors.beneficiary_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="bank_code">Bank</Label>
                        <select
                            id="bank_code"
                            name="bank_code"
                            required
                            class="border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-1 focus-visible:outline-none"
                        >
                            <option value="">Select a bank</option>
                            <option
                                v-for="bank in banks"
                                :key="bank.code"
                                :value="bank.code"
                            >
                                {{ bank.name }}
                            </option>
                        </select>
                        <InputError :message="errors.bank_code" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="account_number">Account number</Label>
                        <Input
                            id="account_number"
                            name="account_number"
                            inputmode="numeric"
                            required
                        />
                        <InputError :message="errors.account_number" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="bank_branch">Branch</Label>
                        <Input id="bank_branch" name="bank_branch" />
                        <InputError :message="errors.bank_branch" />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="bank_address">
                            Bank address
                            <span class="text-muted-foreground font-normal">
                                (optional)
                            </span>
                        </Label>
                        <Input id="bank_address" name="bank_address" />
                        <InputError :message="errors.bank_address" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="swift_code">
                            SWIFT
                            <span class="text-muted-foreground font-normal">
                                (optional)
                            </span>
                        </Label>
                        <Input id="swift_code" name="swift_code" />
                        <InputError :message="errors.swift_code" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="tpin">
                            TPIN
                            <span class="text-muted-foreground font-normal">
                                (optional)
                            </span>
                        </Label>
                        <Input id="tpin" name="tpin" inputmode="numeric" />
                        <InputError :message="errors.tpin" />
                    </div>
                </div>
            </template>

            <template v-else>
                <div class="grid gap-6 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="mobile_number">Mobile-money number</Label>
                        <Input
                            id="mobile_number"
                            name="mobile_number"
                            type="tel"
                            required
                            placeholder="0967 123 456"
                        />
                        <InputError :message="errors.mobile_number" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="network">Network</Label>
                        <select
                            id="network"
                            name="network"
                            required
                            class="border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-1 focus-visible:outline-none"
                        >
                            <option value="mtn">MTN</option>
                            <option value="airtel">Airtel</option>
                        </select>
                        <InputError :message="errors.network" />
                    </div>
                </div>
            </template>

            <p
                class="text-muted-foreground flex items-start gap-2 rounded-lg border p-3 text-sm"
            >
                <Lock class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span>
                    These details are encrypted before they are stored, and we
                    check the account with your bank or wallet provider before
                    saving it.
                </span>
            </p>

            <Button type="submit" :disabled="processing">
                <Spinner v-if="processing" class="size-4" />
                Check and save account
            </Button>
        </Form>

        <Link
            v-if="canContinue"
            :href="sellers.register.step({ step: 'documents' })"
            class="inline-flex"
        >
            <Button variant="outline">Continue to documents</Button>
        </Link>
    </div>
</template>
