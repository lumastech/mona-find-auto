import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SellerLayout from '@/layouts/SellerLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import StorefrontLayout from '@/layouts/StorefrontLayout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'MonaFindAuto';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    /**
     * One layout per area: storefront pages get the public shell, the seller
     * portal and staff console each get their own sidebar shell.
     */
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            case name.startsWith('seller/'):
                return SellerLayout;
            case name.startsWith('admin/'):
                return AdminLayout;
            default:
                return StorefrontLayout;
        }
    },
    progress: {
        color: '#C6A75E',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
