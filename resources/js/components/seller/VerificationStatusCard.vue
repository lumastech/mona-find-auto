<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { AlertTriangle, BadgeCheck, Clock } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import seller from '@/routes/seller';
import type { SellerVerificationState } from '@/types';

/**
 * Where a seller's application has got to, and what they can do about it.
 *
 * Blockers are listed rather than summarised, because "your application is
 * incomplete" tells a seller nothing they can act on and "add your PACRA
 * number" does.
 */
withDefaults(
    defineProps<{
        status: SellerVerificationState;
        blockers?: string[];
        rejectionReason?: string | null;
        compact?: boolean;
    }>(),
    { blockers: () => [], rejectionReason: null, compact: false },
);
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="flex flex-wrap items-center gap-2 text-base">
                <component
                    :is="
                        status.verified
                            ? BadgeCheck
                            : status.in_queue
                              ? Clock
                              : AlertTriangle
                    "
                    class="size-4"
                    aria-hidden="true"
                />
                Verification
                <Badge :variant="status.verified ? 'default' : 'secondary'">
                    {{ status.label }}
                </Badge>
            </CardTitle>
        </CardHeader>

        <CardContent class="space-y-4 text-sm">
            <p class="text-muted-foreground">{{ status.guidance }}</p>

            <div
                v-if="rejectionReason"
                class="border-destructive/40 bg-destructive/5 rounded-lg border p-3"
            >
                <p class="font-medium">Why it was turned down</p>
                <p class="text-muted-foreground mt-1">{{ rejectionReason }}</p>
            </div>

            <div v-if="blockers.length" class="space-y-2">
                <p class="font-medium">Before we can badge you as Verified</p>
                <ul class="text-muted-foreground list-disc space-y-1 pl-5">
                    <li v-for="blocker in blockers" :key="blocker">
                        {{ blocker }}
                    </li>
                </ul>
            </div>

            <Button v-if="compact" as-child variant="outline" size="sm">
                <Link :href="seller.verification.show()">
                    See the full history
                </Link>
            </Button>
        </CardContent>
    </Card>
</template>
