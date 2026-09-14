<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Plus, Users } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import financePolicies from '@/routes/admin/finance/policies';
import type { LabelledOption, MonetisationPolicyRow } from '@/types';

/**
 * The terms sellers trade on.
 *
 * Editing here is safe, and the page says so, because it looks as though it
 * should not be: no order ever reads one of these rows. Terms are frozen onto
 * an order when the money arrives, so raising a commission changes what
 * tomorrow's orders are charged and cannot reach a sale already settled.
 *
 * There is no delete. A policy is retired instead, and its sellers keep it
 * until somebody moves them deliberately — repricing a shop as a side effect
 * of tidying a list is not something to be able to do by accident.
 */
defineProps<{
    policies: MonetisationPolicyRow[];
    commissionTypes: LabelledOption[];
    platform: { vat_on_commission_percent: string; reserve_percent: string };
}>();

const creating = ref(false);
const editing = ref<string | null>(null);
</script>

<template>
    <Head title="Monetisation policies" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Monetisation policies"
                description="What MonaFind charges each seller. Changing a policy affects orders paid from now on — orders already settled keep the terms they were snapshotted with."
            />

            <div class="flex gap-2">
                <Button variant="outline" as-child>
                    <Link :href="financePolicies.sellers().url">
                        <Users class="size-4" aria-hidden="true" />
                        Who is on what
                    </Link>
                </Button>
                <Button @click="creating = !creating">
                    <Plus class="size-4" aria-hidden="true" />
                    New policy
                </Button>
            </div>
        </div>

        <Card class="bg-muted/40">
            <CardContent class="flex flex-wrap gap-x-8 gap-y-2 p-4 text-sm">
                <div>
                    <span class="text-muted-foreground">
                        VAT on commission
                    </span>
                    <span class="ml-2 font-medium">
                        {{ platform.vat_on_commission_percent }}%
                    </span>
                </div>
                <div>
                    <span class="text-muted-foreground">
                        Direct-seller reserve
                    </span>
                    <span class="ml-2 font-medium">
                        {{ platform.reserve_percent }}%
                    </span>
                </div>
                <p class="text-muted-foreground basis-full text-xs">
                    Both are platform-wide and live in settings, not in a policy
                    — a per-seller VAT rate would be a tax fiction.
                </p>
            </CardContent>
        </Card>

        <Card v-if="creating">
            <CardHeader>
                <CardTitle class="text-base">New policy</CardTitle>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="financePolicies.store.form()"
                    v-slot="{ errors, processing }"
                    class="grid gap-4 sm:grid-cols-2"
                    reset-on-success
                    @success="creating = false"
                >
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="new-name">Name</Label>
                        <Input id="new-name" name="name" required />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="new-description">Description</Label>
                        <Input id="new-description" name="description" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="new-type">Commission type</Label>
                        <select
                            id="new-type"
                            name="commission_type"
                            class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        >
                            <option
                                v-for="type in commissionTypes"
                                :key="type.value"
                                :value="type.value"
                            >
                                {{ type.label }}
                            </option>
                        </select>
                        <InputError :message="errors.commission_type" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="new-percent">Commission (%)</Label>
                        <Input
                            id="new-percent"
                            name="commission_percent"
                            inputmode="decimal"
                            placeholder="7.50"
                        />
                        <InputError :message="errors.commission_percent" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="new-flat">Flat commission (K)</Label>
                        <Input
                            id="new-flat"
                            name="commission_flat"
                            inputmode="decimal"
                            placeholder="0.00"
                        />
                        <InputError :message="errors.commission_flat" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="new-addon">Add-on fee (K)</Label>
                        <Input
                            id="new-addon"
                            name="addon_fee"
                            inputmode="decimal"
                            placeholder="0.00"
                        />
                        <InputError :message="errors.addon_fee" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="new-referral">Referral fee (%)</Label>
                        <Input
                            id="new-referral"
                            name="referral_fee_percent"
                            inputmode="decimal"
                            placeholder="0.00"
                        />
                        <InputError :message="errors.referral_fee_percent" />
                    </div>

                    <label
                        class="flex items-center gap-2 self-end text-sm sm:col-span-1"
                    >
                        <Checkbox name="is_default" />
                        Make this the default
                    </label>

                    <div class="flex justify-end gap-2 sm:col-span-2">
                        <Button
                            type="button"
                            variant="ghost"
                            @click="creating = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="processing">
                            <Spinner v-if="processing" />
                            Create policy
                        </Button>
                    </div>
                </Form>
            </CardContent>
        </Card>

        <Card v-for="policy in policies" :key="policy.slug">
            <CardContent class="space-y-4 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold">{{ policy.name }}</h3>
                            <Badge v-if="policy.is_default">Default</Badge>
                            <Badge v-if="!policy.is_active" variant="outline">
                                Retired
                            </Badge>
                        </div>
                        <p class="text-muted-foreground mt-1 text-sm">
                            {{ policy.commission_description }}
                            <template v-if="policy.addon_fee_ngwee > 0">
                                · add-on
                                <Money :amount="policy.addon_fee_ngwee" />
                            </template>
                            <template
                                v-if="policy.referral_fee_percent !== '0.00'"
                            >
                                · referral
                                {{ policy.referral_fee_percent }}%
                            </template>
                        </p>
                        <p
                            v-if="policy.description"
                            class="text-muted-foreground mt-1 text-sm"
                        >
                            {{ policy.description }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Badge variant="outline">
                            {{ policy.seller_count ?? 0 }} assigned
                        </Badge>

                        <Form
                            v-if="!policy.is_default"
                            v-bind="financePolicies.default.form(policy.slug)"
                            v-slot="{ processing }"
                        >
                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                :disabled="processing"
                            >
                                Make default
                            </Button>
                        </Form>

                        <Form
                            v-if="!policy.is_default"
                            v-bind="financePolicies.active.form(policy.slug)"
                            v-slot="{ processing }"
                        >
                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                :disabled="processing"
                            >
                                {{ policy.is_active ? 'Retire' : 'Reinstate' }}
                            </Button>
                        </Form>

                        <Button
                            size="sm"
                            variant="ghost"
                            @click="
                                editing =
                                    editing === policy.slug ? null : policy.slug
                            "
                        >
                            {{ editing === policy.slug ? 'Close' : 'Edit' }}
                        </Button>
                    </div>
                </div>

                <Form
                    v-if="editing === policy.slug"
                    v-bind="financePolicies.update.form(policy.slug)"
                    v-slot="{ errors, processing }"
                    class="grid gap-4 border-t pt-4 sm:grid-cols-2"
                    @success="editing = null"
                >
                    <div class="grid gap-2 sm:col-span-2">
                        <Label :for="`${policy.slug}-name`">Name</Label>
                        <Input
                            :id="`${policy.slug}-name`"
                            name="name"
                            :default-value="policy.name"
                            required
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label :for="`${policy.slug}-description`">
                            Description
                        </Label>
                        <Input
                            :id="`${policy.slug}-description`"
                            name="description"
                            :default-value="policy.description ?? ''"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label :for="`${policy.slug}-type`">
                            Commission type
                        </Label>
                        <select
                            :id="`${policy.slug}-type`"
                            name="commission_type"
                            class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        >
                            <option
                                v-for="type in commissionTypes"
                                :key="type.value"
                                :value="type.value"
                                :selected="
                                    type.value === policy.commission_type
                                "
                            >
                                {{ type.label }}
                            </option>
                        </select>
                    </div>

                    <div class="grid gap-2">
                        <Label :for="`${policy.slug}-percent`">
                            Commission (%)
                        </Label>
                        <Input
                            :id="`${policy.slug}-percent`"
                            name="commission_percent"
                            inputmode="decimal"
                            :default-value="policy.commission_percent"
                        />
                        <InputError :message="errors.commission_percent" />
                    </div>

                    <div class="grid gap-2">
                        <Label :for="`${policy.slug}-flat`">
                            Flat commission (K)
                        </Label>
                        <Input
                            :id="`${policy.slug}-flat`"
                            name="commission_flat"
                            inputmode="decimal"
                            :default-value="
                                (policy.commission_flat_ngwee / 100).toFixed(2)
                            "
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label :for="`${policy.slug}-addon`">
                            Add-on fee (K)
                        </Label>
                        <Input
                            :id="`${policy.slug}-addon`"
                            name="addon_fee"
                            inputmode="decimal"
                            :default-value="
                                (policy.addon_fee_ngwee / 100).toFixed(2)
                            "
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label :for="`${policy.slug}-referral`">
                            Referral fee (%)
                        </Label>
                        <Input
                            :id="`${policy.slug}-referral`"
                            name="referral_fee_percent"
                            inputmode="decimal"
                            :default-value="policy.referral_fee_percent"
                        />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label :for="`${policy.slug}-reason`">
                            Reason (recorded in the audit trail)
                        </Label>
                        <Input :id="`${policy.slug}-reason`" name="reason" />
                    </div>

                    <div class="flex justify-end sm:col-span-2">
                        <Button type="submit" :disabled="processing">
                            <Spinner v-if="processing" />
                            Save policy
                        </Button>
                    </div>
                </Form>
            </CardContent>
        </Card>
    </div>
</template>
