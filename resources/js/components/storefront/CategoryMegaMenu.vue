<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import categories from '@/routes/categories';
import type { NavCategory } from '@/types';

/**
 * "Browse parts" — the catalogue's headings and what sits under each.
 *
 * Two levels, not three. The tree goes three deep, but a third tier of
 * fly-out on a phone is a target nobody can hit; the leaves are reached from
 * the category page, where there is room to lay them out. The panel is a
 * plain disclosure rather than a hover menu for the same reason: hover is not
 * a gesture a touch screen has.
 *
 * The tree arrives on the shared `nav` prop, so this renders with the page
 * rather than fetching on open.
 */
const page = usePage();
const tree = computed<NavCategory[]>(() => page.props.nav?.categories ?? []);

const open = ref(false);
const panel = ref<HTMLElement | null>(null);

function close(): void {
    open.value = false;
}

/** Closing on blur rather than on document click keeps focus behaviour sane. */
function onFocusOut(event: FocusEvent): void {
    const next = event.relatedTarget;

    if (next instanceof Node && panel.value?.contains(next) === true) {
        return;
    }

    close();
}
</script>

<template>
    <div
        v-if="tree.length > 0"
        ref="panel"
        class="relative"
        @focusout="onFocusOut"
        @keydown.escape="close"
    >
        <button
            type="button"
            class="flex h-9 items-center gap-1.5 rounded-md px-3 text-sm font-medium hover:bg-white/10"
            :aria-expanded="open"
            aria-haspopup="true"
            @click="open = !open"
        >
            Browse parts
            <ChevronDown
                class="size-4 transition-transform"
                :class="open && 'rotate-180'"
                aria-hidden="true"
            />
        </button>

        <div
            v-show="open"
            class="bg-popover text-popover-foreground border-border absolute top-full left-0 z-50 mt-1 max-h-[70vh] w-[min(92vw,56rem)] overflow-y-auto rounded-xl border p-5 shadow-lg"
        >
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="root in tree" :key="root.id" class="min-w-0">
                    <Link
                        :href="categories.show(root.slug).url"
                        class="font-display hover:text-trust-text text-sm font-semibold"
                        @click="close"
                    >
                        {{ root.name }}
                    </Link>
                    <ul class="mt-1.5 grid gap-0.5">
                        <li v-for="child in root.children" :key="child.id">
                            <Link
                                :href="categories.show(child.slug).url"
                                class="text-muted-foreground hover:text-foreground block truncate py-0.5 text-sm"
                                @click="close"
                            >
                                {{ child.name }}
                            </Link>
                        </li>
                    </ul>
                </div>
            </div>

            <Link
                :href="categories.index().url"
                class="text-trust-text mt-5 inline-block text-sm font-medium underline-offset-4 hover:underline"
                @click="close"
            >
                See every category
            </Link>
        </div>
    </div>
</template>
