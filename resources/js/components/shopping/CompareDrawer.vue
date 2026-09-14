<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Scale, X } from '@lucide/vue';
import Money from '@/components/Money.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useCompare } from '@/composables/useCompare';
import listings from '@/routes/listings';

/**
 * The comparison: up to four listings side by side, and only the rows where
 * they actually differ.
 *
 * Held in sessionStorage, so it survives moving between search results and
 * listing pages and is forgotten when the tab is. Nothing here talks to the
 * server.
 *
 * The table scrolls sideways inside its own container rather than widening
 * the page — four columns do not fit on the phones most of these buyers are
 * using, and a storefront that scrolls horizontally is a storefront where the
 * header drifts off.
 */
const { entries, drawerOpen, count, differences, remove, clear } = useCompare();
</script>

<template>
    <!-- The launcher, once there is something to compare. -->
    <div
        v-if="count > 0 && !drawerOpen"
        class="fixed inset-x-0 bottom-0 z-40 flex justify-center p-4"
    >
        <Button class="shadow-lg" @click="drawerOpen = true">
            <Scale class="size-4" aria-hidden="true" />
            Compare {{ count }} {{ count === 1 ? 'listing' : 'listings' }}
        </Button>
    </div>

    <Sheet v-model:open="drawerOpen">
        <SheetContent side="bottom" class="max-h-[85vh] overflow-y-auto p-6">
            <SheetHeader class="p-0">
                <SheetTitle>Comparing {{ count }} listings</SheetTitle>
            </SheetHeader>

            <p v-if="count === 1" class="text-muted-foreground mt-2 text-sm">
                Add another listing to see how they differ.
            </p>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[36rem] text-sm">
                    <thead>
                        <tr>
                            <th class="w-32 p-2 text-left"></th>
                            <th
                                v-for="entry in entries"
                                :key="entry.id"
                                class="min-w-40 p-2 text-left align-top"
                            >
                                <div class="space-y-2">
                                    <div class="flex items-start gap-1">
                                        <Link
                                            :href="
                                                listings.show(entry.slug).url
                                            "
                                            class="line-clamp-2 font-medium underline-offset-4 hover:underline"
                                        >
                                            {{ entry.name }}
                                        </Link>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="size-6 shrink-0"
                                            :aria-label="`Remove ${entry.name} from the comparison`"
                                            @click="remove(entry.id)"
                                        >
                                            <X
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                        </Button>
                                    </div>

                                    <img
                                        v-if="entry.thumbnail_url"
                                        :src="entry.thumbnail_url"
                                        :alt="entry.name"
                                        class="bg-muted aspect-4/3 w-full rounded object-cover"
                                    />

                                    <p class="font-semibold">
                                        <Money
                                            v-if="entry.price_ngwee !== null"
                                            :amount="entry.price_ngwee"
                                        />
                                        <span
                                            v-else
                                            class="text-muted-foreground"
                                        >
                                            On request
                                        </span>
                                    </p>
                                </div>
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <!--
                            Only the rows that differ. Four columns reading
                            the same thing is four columns of noise — the
                            buyer is looking for the line that is not
                            identical.
                        -->
                        <tr
                            v-for="row in differences"
                            :key="row.label"
                            class="border-t"
                        >
                            <th
                                scope="row"
                                class="text-muted-foreground p-2 text-left font-normal"
                            >
                                {{ row.label }}
                            </th>
                            <td
                                v-for="(value, index) in row.values"
                                :key="index"
                                class="p-2"
                            >
                                {{ value }}
                            </td>
                        </tr>

                        <tr v-if="differences.length === 0" class="border-t">
                            <td
                                :colspan="entries.length + 1"
                                class="text-muted-foreground p-4 text-center"
                            >
                                These listings match on everything except price.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex justify-end">
                <Button variant="ghost" size="sm" @click="clear">
                    Clear comparison
                </Button>
            </div>
        </SheetContent>
    </Sheet>
</template>
