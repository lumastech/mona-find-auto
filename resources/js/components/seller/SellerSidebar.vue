<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BadgeCheck,
    ClipboardCheck,
    FileSpreadsheet,
    FileText,
    FileQuestion,
    Handshake,
    LayoutGrid,
    MessageSquare,
    PackageSearch,
    Receipt,
    ScrollText,
    Star,
    Store,
    Truck,
    Wallet,
} from '@lucide/vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import seller from '@/routes/seller';
import sellerEndorsements from '@/routes/seller/endorsements';
import sellerFulfilment from '@/routes/seller/fulfilment';
import sellerListings from '@/routes/seller/listings';
import sellerOrders from '@/routes/seller/orders';
import sellerQuotations from '@/routes/seller/quotations';
import sellerRatings from '@/routes/seller/ratings';
import sellerStatements from '@/routes/seller/statements';
import sellerStock from '@/routes/seller/stock';
import type { NavItem } from '@/types';

/**
 * Seller portal navigation. Items whose routes do not exist yet are added by
 * the module that owns them.
 */
const navItems: NavItem[] = [
    { title: 'Dashboard', href: seller.dashboard(), icon: LayoutGrid },
    { title: 'Shop details', href: seller.profile.edit(), icon: Store },
    { title: 'Orders', href: sellerOrders.index(), icon: Receipt },
    { title: 'Listings', href: sellerListings.index(), icon: PackageSearch },
    { title: 'Stock', href: sellerStock.index(), icon: ClipboardCheck },
    {
        title: 'Messages',
        href: seller.messages.index(),
        icon: MessageSquare,
    },
    {
        title: 'Quote requests',
        href: sellerQuotations.index(),
        icon: FileQuestion,
    },
    { title: 'Delivery', href: sellerFulfilment.edit(), icon: Truck },
    /* What buyers said, the shop's one reply, and the trust score it feeds. */
    { title: 'Reviews', href: sellerRatings.index(), icon: Star },
    /* Mechanics asking this shop to vouch for them. */
    {
        title: 'Endorsements',
        href: sellerEndorsements.index(),
        icon: Handshake,
    },
    { title: 'Policies', href: seller.policies.index(), icon: ScrollText },
    {
        title: 'Payout accounts',
        href: seller.payoutAccounts.index(),
        icon: Wallet,
    },
    /* Closed monthly figures, and MonaFind's commission invoices behind them. */
    {
        title: 'Statements',
        href: sellerStatements.index(),
        icon: FileSpreadsheet,
    },
    { title: 'Documents', href: seller.documents.index(), icon: FileText },
    {
        title: 'Verification',
        href: seller.verification.show(),
        icon: BadgeCheck,
    },
];

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="seller.dashboard()">
                            <Store class="text-primary size-5" />
                            <span class="font-semibold">Seller portal</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <SidebarGroup class="px-2 py-0">
                <SidebarGroupLabel>Selling</SidebarGroupLabel>
                <SidebarMenu>
                    <SidebarMenuItem v-for="item in navItems" :key="item.title">
                        <SidebarMenuButton
                            as-child
                            :is-active="isCurrentUrl(item.href)"
                            :tooltip="item.title"
                        >
                            <Link :href="item.href">
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
