<script setup lang="ts">
import AdminSidebar from '@/components/admin/AdminSidebar.vue';
import AnnouncementBanner from '@/components/AnnouncementBanner.vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

/**
 * The staff console shell, mounted at /admin behind the staff role check.
 */
const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <AppShell variant="sidebar">
        <AdminSidebar />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <AnnouncementBanner />
            <slot />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
