<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

/**
 * Buyer dashboard placeholder. Orders, enquiries and wishlists arrive with
 * the Orders and Shopping modules.
 */
</script>

<template>
    <Head title="Your account" />

    <h1 class="text-xl font-semibold tracking-tight">Your account</h1>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <Card v-for="panel in ['Orders', 'Enquiries', 'Wishlist']" :key="panel">
            <CardHeader>
                <CardTitle class="text-base">{{ panel }}</CardTitle>
            </CardHeader>
            <CardContent class="text-muted-foreground text-sm">
                Nothing here yet.
            </CardContent>
        </Card>
    </div>
</template>
