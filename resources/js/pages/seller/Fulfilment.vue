<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { MapPin, Truck } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import fulfilment from '@/routes/seller/fulfilment';

/**
 * What the shop offers buyers at checkout.
 *
 * Short, but it decides whether a buyer sees "Collect" or "Delivery" against
 * this shop at all — and a shop that offers neither cannot be ordered from,
 * which is why the server refuses that combination rather than leaving the
 * buyer to discover it.
 *
 * The fee is typed in kwacha; the server stores ngwee. Release 1 is one flat
 * rate per shop: per-zone and distance-based pricing arrive later behind the
 * same form.
 */
const props = defineProps<{
    fulfilment: {
        offers_pickup: boolean;
        offers_delivery: boolean;
        delivery_fee_ngwee: number;
        delivery_note: string | null;
        address: string;
        latitude: number | null;
        longitude: number | null;
    };
}>();

const form = useForm({
    offers_pickup: props.fulfilment.offers_pickup,
    offers_delivery: props.fulfilment.offers_delivery,
    delivery_fee: (props.fulfilment.delivery_fee_ngwee / 100).toFixed(2),
    delivery_note: props.fulfilment.delivery_note ?? '',
});

const submit = (): void => {
    form.put(fulfilment.update().url, { preserveScroll: true });
};
</script>

<template>
    <Head title="Delivery and collection" />

    <div class="max-w-2xl space-y-6 px-4 sm:px-6 lg:px-8">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">
                Delivery and collection
            </h1>
            <p class="text-muted-foreground text-sm">
                What buyers are offered when they check out with you.
            </p>
        </header>

        <form class="space-y-4" @submit.prevent="submit">
            <Card>
                <CardHeader class="gap-1">
                    <h2 class="flex items-center gap-2 font-semibold">
                        <MapPin class="size-4" aria-hidden="true" />
                        Collection
                    </h2>
                </CardHeader>
                <CardContent class="space-y-3">
                    <label class="flex items-start gap-3 text-sm">
                        <Checkbox
                            id="offers_pickup"
                            :model-value="form.offers_pickup"
                            @update:model-value="
                                form.offers_pickup = Boolean($event)
                            "
                        />
                        <span>
                            <span class="block font-medium">
                                Buyers can collect from my premises
                            </span>
                            <span class="text-muted-foreground">
                                {{ props.fulfilment.address }}
                            </span>
                        </span>
                    </label>

                    <InputError :message="form.errors.offers_pickup" />
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="gap-1">
                    <h2 class="flex items-center gap-2 font-semibold">
                        <Truck class="size-4" aria-hidden="true" />
                        Delivery
                    </h2>
                </CardHeader>
                <CardContent class="space-y-4">
                    <label class="flex items-start gap-3 text-sm">
                        <Checkbox
                            id="offers_delivery"
                            :model-value="form.offers_delivery"
                            @update:model-value="
                                form.offers_delivery = Boolean($event)
                            "
                        />
                        <span class="font-medium"
                            >I deliver parts to buyers</span
                        >
                    </label>

                    <div v-if="form.offers_delivery" class="space-y-4">
                        <div class="space-y-1">
                            <Label for="delivery_fee"
                                >Delivery charge (K)</Label
                            >
                            <Input
                                id="delivery_fee"
                                v-model="form.delivery_fee"
                                type="number"
                                step="0.01"
                                min="0"
                                inputmode="decimal"
                            />
                            <p class="text-muted-foreground text-xs">
                                One flat charge per order, whatever is in it.
                            </p>
                            <InputError :message="form.errors.delivery_fee" />
                        </div>

                        <div class="space-y-1">
                            <Label for="delivery_note">
                                Anything buyers should know
                            </Label>
                            <Input
                                id="delivery_note"
                                v-model="form.delivery_note"
                                placeholder="Lusaka only, next working day."
                            />
                            <InputError :message="form.errors.delivery_note" />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Button type="submit" :disabled="form.processing">Save</Button>
        </form>
    </div>
</template>
