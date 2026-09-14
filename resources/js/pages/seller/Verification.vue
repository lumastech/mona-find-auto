<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import VerificationStatusCard from '@/components/seller/VerificationStatusCard.vue';
import VerificationBadge from '@/components/storefront/VerificationBadge.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { SellerVerificationEvent, SellerVerificationState } from '@/types';

/**
 * The answer to "where has my application got to?".
 *
 * Reviewer notes are deliberately not on this page — the seller sees the
 * decision and the reason for it, not the reviewer's working.
 */
defineProps<{
    status: SellerVerificationState;
    seller: {
        business_name: string;
        registration_number: string | null;
        submitted_at: string | null;
        verified_at: string | null;
        inspection_scheduled_for: string | null;
        rejection_reason: string | null;
    };
    blockers: string[];
    history: SellerVerificationEvent[];
}>();

const dateTime = (value: string | null): string =>
    value === null ? '—' : new Date(value).toLocaleString();
</script>

<template>
    <Head title="Verification" />

    <div class="space-y-6 p-4">
        <Heading title="Verification" :description="seller.business_name" />

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <VerificationStatusCard
                    :status="status"
                    :blockers="blockers"
                    :rejection-reason="seller.rejection_reason"
                />

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">History</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ol v-if="history.length" class="space-y-4">
                            <li
                                v-for="event in history"
                                :key="event.id"
                                class="border-border border-l-2 pl-4 text-sm"
                            >
                                <p class="font-medium">{{ event.summary }}</p>
                                <p
                                    v-if="event.reason"
                                    class="text-muted-foreground mt-1"
                                >
                                    {{ event.reason }}
                                </p>
                                <p class="text-muted-foreground mt-1 text-xs">
                                    {{ event.actor }} ·
                                    {{ dateTime(event.created_at) }}
                                </p>
                            </li>
                        </ol>
                        <p v-else class="text-muted-foreground text-sm">
                            Nothing has happened yet.
                        </p>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">What buyers see</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4 text-sm">
                    <VerificationBadge
                        :verified="status.verified"
                        :label="status.public_label"
                    />

                    <dl class="space-y-2">
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">
                                Registration number
                            </dt>
                            <dd>{{ seller.registration_number ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Submitted</dt>
                            <dd>{{ dateTime(seller.submitted_at) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Verified</dt>
                            <dd>{{ dateTime(seller.verified_at) }}</dd>
                        </div>
                        <div
                            v-if="seller.inspection_scheduled_for"
                            class="flex justify-between gap-4"
                        >
                            <dt class="text-muted-foreground">Inspection</dt>
                            <dd>
                                {{ dateTime(seller.inspection_scheduled_for) }}
                            </dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
