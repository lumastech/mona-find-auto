<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import categories from '@/routes/categories';
import type { CategoryNode, MakeOption } from '@/types';

/**
 * The top of the catalogue.
 *
 * Server-rendered like the listing pages beneath it: this is the page a
 * search engine crawls to find them.
 */
defineProps<{
    tree: CategoryNode[];
    popularMakes: Array<MakeOption & { slug: string }>;
}>();
</script>

<template>
    <Head title="Browse parts">
        <meta
            name="description"
            content="Browse car and truck parts by category on MonaFindAuto — engine, transmission, suspension, brakes, body and more, from verified Zambian sellers."
        />
    </Head>

    <div class="space-y-8">
        <header class="space-y-2">
            <h1 class="text-2xl font-semibold tracking-tight">Browse parts</h1>
            <p class="text-muted-foreground">
                Every part on MonaFindAuto, filed by the system it belongs to.
            </p>
        </header>

        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            <Card v-for="root in tree" :key="root.id">
                <CardHeader>
                    <CardTitle class="text-base">
                        <Link
                            :href="categories.show(root.slug).url"
                            class="underline-offset-4 hover:underline"
                        >
                            {{ root.name }}
                        </Link>
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <ul class="space-y-1 text-sm">
                        <li v-for="group in root.children" :key="group.id">
                            <Link
                                :href="categories.show(group.slug).url"
                                class="text-muted-foreground hover:text-foreground underline-offset-4 hover:underline"
                            >
                                {{ group.name }}
                            </Link>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <section v-if="popularMakes.length" class="space-y-3">
            <h2 class="text-lg font-semibold tracking-tight">Popular makes</h2>
            <ul class="flex flex-wrap gap-2 text-sm">
                <li
                    v-for="make in popularMakes"
                    :key="make.id"
                    class="bg-muted rounded-full px-3 py-1"
                >
                    {{ make.name }}
                </li>
            </ul>
        </section>
    </div>
</template>
