<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

/**
 * Shops that have stopped confirming their stock.
 *
 * Counted per shop rather than per listing: a yard with 300 unconfirmed parts
 * is one phone call, not three hundred problems.
 *
 * Read-only, deliberately. Staff cannot confirm stock on a seller's behalf —
 * a confirmation means "I have looked on the shelf", and a moderator pressing
 * it for somebody would turn the freshness badge into a lie.
 */
defineProps<{
    sellers: {
        data: {
            id: number;
            name: string;
            city: string | null;
            stale_listings_count: number;
            hidden_listings_count: number;
            href: string;
        }[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    thresholds: { ageing: number; hidden: number };
}>();
</script>

<template>
    <Head title="Stale stock" />

    <div class="space-y-6 p-4">
        <Heading
            title="Stale stock"
            :description="`Shops with listings unconfirmed for more than ${thresholds.ageing} days. Past ${thresholds.hidden} days a listing leaves the storefront entirely.`"
        />

        <Card>
            <CardHeader>
                <CardTitle>Somebody should ring these shops</CardTitle>
                <CardDescription>
                    Worst first. Confirming stock is the seller's own act — it
                    cannot be done for them from here.
                </CardDescription>
            </CardHeader>

            <CardContent class="space-y-2">
                <p
                    v-if="sellers.data.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    Every shop is up to date.
                </p>

                <div
                    v-for="seller in sellers.data"
                    :key="seller.id"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3 text-sm"
                >
                    <div>
                        <Link
                            :href="seller.href"
                            class="font-medium underline-offset-4 hover:underline"
                        >
                            {{ seller.name }}
                        </Link>
                        <p
                            v-if="seller.city"
                            class="text-muted-foreground text-xs"
                        >
                            {{ seller.city }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Badge variant="secondary">
                            {{ seller.stale_listings_count }} unconfirmed
                        </Badge>
                        <Badge
                            v-if="seller.hidden_listings_count > 0"
                            variant="destructive"
                        >
                            {{ seller.hidden_listings_count }} hidden
                        </Badge>
                    </div>
                </div>
            </CardContent>
        </Card>

        <nav v-if="sellers.links.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="link in sellers.links"
                :key="link.label"
                :href="link.url ?? '#'"
                class="rounded-md border px-3 py-1 text-sm"
                :class="
                    link.active
                        ? 'bg-primary text-primary-foreground'
                        : link.url
                          ? 'hover:bg-accent'
                          : 'pointer-events-none opacity-50'
                "
                v-html="link.label"
            />
        </nav>
    </div>
</template>
