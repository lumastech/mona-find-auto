<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { search } from '@/routes';

/**
 * The storefront's part search. Submits to the search results page; the
 * Search module takes over the results themselves.
 *
 * `tone` exists because this sits in two places with opposite grounds: the
 * navy masthead, where the default primary button would be navy on navy, and
 * the search page itself, where the page's own tokens are correct.
 */
const {
    placeholder = 'Search parts, e.g. "Toyota Hilux brake pads"',
    tone = 'page',
    initial = '',
} = defineProps<{
    placeholder?: string;
    tone?: 'page' | 'navy';
    initial?: string;
}>();

const term = ref(initial);

function submit() {
    router.get(search.url({ query: { q: term.value } }));
}
</script>

<template>
    <form
        role="search"
        class="flex w-full items-center gap-2"
        @submit.prevent="submit"
    >
        <label class="sr-only" for="storefront-search">Search for parts</label>
        <div class="relative flex-1">
            <Search
                class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                aria-hidden="true"
            />
            <input
                id="storefront-search"
                v-model="term"
                type="search"
                name="q"
                :placeholder="placeholder"
                autocomplete="off"
                class="border-input bg-background text-foreground placeholder:text-muted-foreground focus-visible:ring-brand-gold h-11 w-full rounded-full border py-2 pr-4 pl-10 text-base focus-visible:ring-2 focus-visible:outline-none"
            />
        </div>
        <Button
            type="submit"
            class="h-11 shrink-0 rounded-full px-5"
            :class="
                tone === 'navy' &&
                'bg-brand-beige text-brand-navy hover:bg-white'
            "
        >
            <span class="sr-only sm:not-sr-only">Search</span>
            <Search class="size-4 sm:hidden" aria-hidden="true" />
        </Button>
    </form>
</template>
