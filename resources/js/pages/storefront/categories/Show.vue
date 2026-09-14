<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { reactive, watch } from 'vue';
import ProductCard from '@/components/catalog/ProductCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import categories from '@/routes/categories';
import type {
    BreadcrumbNode,
    ConditionOption,
    MakeOption,
    ProductCard as ProductCardType,
} from '@/types';

/**
 * One category, and the listings filed under it.
 *
 * Browsing a heading shows everything beneath it — "Engine" includes
 * injectors — so a buyer who does not know the exact category still lands on
 * stock rather than an empty page.
 *
 * The order here is newest first. The quality-scored ranking the brief
 * describes belongs to search; keeping half of it here would leave two
 * rankings to hold in step.
 */
const props = defineProps<{
    category: {
        id: number;
        name: string;
        slug: string;
        description: string | null;
    };
    breadcrumb: BreadcrumbNode[];
    children: Array<{ id: number; name: string; slug: string }>;
    listings: { data: ProductCardType[]; links: unknown; total?: number };
    filters: {
        condition?: string | null;
        inspected?: boolean | null;
        make_id?: number | null;
        delivery?: boolean | null;
    };
    conditions: ConditionOption[];
    makes: MakeOption[];
}>();

const filters = reactive({
    condition: props.filters.condition ?? '',
    inspected: Boolean(props.filters.inspected),
    make_id: props.filters.make_id ?? '',
    delivery: Boolean(props.filters.delivery),
});

watch(filters, (current) => {
    router.get(
        categories.show(props.category.slug).url,
        {
            condition: current.condition || undefined,
            inspected: current.inspected || undefined,
            make_id: current.make_id || undefined,
            delivery: current.delivery || undefined,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
});
</script>

<template>
    <Head :title="category.name">
        <meta
            name="description"
            :content="
                category.description ??
                `${category.name} for sale on MonaFindAuto from verified Zambian sellers.`
            "
        />
    </Head>

    <div class="space-y-6">
        <nav aria-label="Breadcrumb">
            <ol
                class="text-muted-foreground flex flex-wrap items-center gap-1 text-sm"
            >
                <li>
                    <Link
                        :href="categories.index().url"
                        class="hover:text-foreground underline-offset-4 hover:underline"
                    >
                        All parts
                    </Link>
                </li>
                <li
                    v-for="node in breadcrumb"
                    :key="node.id"
                    class="flex items-center gap-1"
                >
                    <ChevronRight class="size-3.5" aria-hidden="true" />
                    <Link
                        :href="categories.show(node.slug).url"
                        class="hover:text-foreground underline-offset-4 hover:underline"
                    >
                        {{ node.name }}
                    </Link>
                </li>
            </ol>
        </nav>

        <header class="space-y-2">
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ category.name }}
            </h1>
            <p v-if="category.description" class="text-muted-foreground">
                {{ category.description }}
            </p>
        </header>

        <ul v-if="children.length" class="flex flex-wrap gap-2">
            <li v-for="child in children" :key="child.id">
                <Link :href="categories.show(child.slug).url">
                    <Badge variant="outline">{{ child.name }}</Badge>
                </Link>
            </li>
        </ul>

        <div class="flex flex-wrap items-end gap-4 rounded-lg border p-4">
            <div class="space-y-1">
                <Label for="condition-filter" class="text-xs">Condition</Label>
                <select
                    id="condition-filter"
                    v-model="filters.condition"
                    class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                >
                    <option value="">Any condition</option>
                    <option
                        v-for="option in conditions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>

            <div class="space-y-1">
                <Label for="make-filter" class="text-xs">Make</Label>
                <select
                    id="make-filter"
                    v-model="filters.make_id"
                    class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                >
                    <option value="">Any make</option>
                    <option
                        v-for="make in makes"
                        :key="make.id"
                        :value="make.id"
                    >
                        {{ make.name }}
                    </option>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <Checkbox id="inspected-filter" v-model="filters.inspected" />
                <Label for="inspected-filter" class="text-sm">
                    Inspected by MonaFind
                </Label>
            </div>

            <div class="flex items-center gap-2">
                <Checkbox id="delivery-filter" v-model="filters.delivery" />
                <Label for="delivery-filter" class="text-sm">
                    Delivery available
                </Label>
            </div>
        </div>

        <div
            v-if="listings.data.length"
            class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4"
        >
            <ProductCard
                v-for="listing in listings.data"
                :key="listing.id"
                :listing="listing"
            />
        </div>

        <div v-else class="rounded-lg border border-dashed p-12 text-center">
            <p class="text-muted-foreground">
                Nothing listed here yet. Try a broader category.
            </p>
            <Button variant="outline" class="mt-4" as-child>
                <Link :href="categories.index().url">Browse all parts</Link>
            </Button>
        </div>
    </div>
</template>
