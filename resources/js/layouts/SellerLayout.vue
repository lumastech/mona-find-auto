<script setup lang="ts">
import AnnouncementBanner from '@/components/AnnouncementBanner.vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import SellerSidebar from '@/components/seller/SellerSidebar.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

/**
 * The seller portal shell, mounted at /seller behind the seller role check.
 */
const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <AppShell variant="sidebar">
        <SellerSidebar />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <AnnouncementBanner />
            <slot />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
