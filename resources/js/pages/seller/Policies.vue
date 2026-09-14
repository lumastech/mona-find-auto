<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { History, ShieldCheck } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import sellerRoutes from '@/routes/seller';
import type {
    PlatformMinimumRefund,
    PolicyTypeOption,
    PolicyTypeValue,
    SellerPolicy,
} from '@/types';

/**
 * A seller's policy manager.
 *
 * Saving publishes a new version rather than editing the old one, and the
 * history stays on screen: the text a buyer accepted six weeks ago is the
 * text a dispute will be argued against, so a seller should be able to read
 * it back.
 */
const props = defineProps<{
    policyTypes: PolicyTypeOption[];
    current: Record<string, SellerPolicy>;
    history: SellerPolicy[];
    platformMinimumRefund: PlatformMinimumRefund;
}>();

const editing = ref<PolicyTypeValue | null>(null);

const versionsOf = (type: PolicyTypeValue): SellerPolicy[] =>
    props.history.filter(
        (policy) => policy.type === type && !policy.is_current,
    );

const shortDate = (value: string): string =>
    new Date(value).toLocaleDateString();
</script>

<template>
    <Head title="Policies" />

    <div class="space-y-6 p-4">
        <Heading
            title="Policies"
            description="What buyers agree to when they order from you. Editing publishes a new version; buyers stay bound to the one they accepted."
        />

        <Card v-for="type in policyTypes" :key="type.value">
            <CardHeader>
                <CardTitle class="flex flex-wrap items-center gap-2 text-base">
                    {{ type.label }}
                    <Badge v-if="current[type.value]" variant="outline">
                        Version {{ current[type.value].version }}
                    </Badge>
                    <Badge v-else-if="type.required" variant="destructive">
                        Not published
                    </Badge>
                </CardTitle>
            </CardHeader>

            <CardContent class="space-y-4">
                <p class="text-muted-foreground text-sm">{{ type.guidance }}</p>

                <div
                    v-if="type.shows_platform_minimum"
                    class="bg-muted/50 flex gap-3 rounded-lg border p-3"
                >
                    <ShieldCheck
                        class="text-primary mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <p class="text-muted-foreground text-sm">
                        <span class="text-foreground font-medium">
                            MonaFind minimum:
                        </span>
                        {{ platformMinimumRefund.statement }}
                    </p>
                </div>

                <div
                    v-if="current[type.value] && editing !== type.value"
                    class="space-y-3"
                >
                    <div class="text-sm whitespace-pre-line">
                        {{ current[type.value].body }}
                    </div>
                    <p class="text-muted-foreground text-xs">
                        In force since
                        {{ shortDate(current[type.value].effective_from) }}
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="editing = type.value"
                    >
                        Publish a new version
                    </Button>
                </div>

                <Form
                    v-else
                    v-bind="
                        sellerRoutes.policies.store.form({ type: type.value })
                    "
                    v-slot="{ errors, processing }"
                    class="space-y-3"
                    @success="editing = null"
                >
                    <Label :for="`body-${type.value}`" class="sr-only">
                        {{ type.label }}
                    </Label>
                    <textarea
                        :id="`body-${type.value}`"
                        name="body"
                        rows="6"
                        :value="current[type.value]?.body ?? ''"
                        required
                        class="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-1 focus-visible:outline-none"
                    />
                    <InputError :message="errors.body" />

                    <div class="flex flex-wrap gap-2">
                        <Button type="submit" size="sm" :disabled="processing">
                            <Spinner v-if="processing" class="size-4" />
                            Publish
                        </Button>
                        <Button
                            v-if="current[type.value]"
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="editing = null"
                        >
                            Cancel
                        </Button>
                    </div>
                </Form>

                <details v-if="versionsOf(type.value).length" class="text-sm">
                    <summary
                        class="text-muted-foreground flex cursor-pointer items-center gap-2"
                    >
                        <History class="size-4" aria-hidden="true" />
                        {{ versionsOf(type.value).length }} earlier version{{
                            versionsOf(type.value).length === 1 ? '' : 's'
                        }}
                    </summary>

                    <ul class="mt-3 space-y-3">
                        <li
                            v-for="version in versionsOf(type.value)"
                            :key="version.id"
                            class="border-border border-l-2 pl-4"
                        >
                            <p class="font-medium">
                                Version {{ version.version }}
                                <span class="text-muted-foreground font-normal">
                                    · {{ shortDate(version.effective_from) }}
                                </span>
                            </p>
                            <p class="text-muted-foreground mt-1">
                                {{ version.excerpt }}
                            </p>
                        </li>
                    </ul>
                </details>
            </CardContent>
        </Card>
    </div>
</template>
