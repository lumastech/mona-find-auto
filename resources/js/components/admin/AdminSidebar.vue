<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BellRing,
    BadgeCheck,
    FileText,
    PackageSearch,
    Settings2,
    UserCog,
    Banknote,
    Download,
    Landmark,
    LayoutGrid,
    MessageSquareWarning,
    ScrollText,
    Receipt,
    Percent,
    Scale,
    SearchX,
    ShieldCheck,
    SlidersHorizontal,
    Star,
    TrendingUp,
    Users,
    Wrench,
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
import admin, { searchInsights, staleStock } from '@/routes/admin';
import adminListings from '@/routes/admin/listings';
import adminAudit from '@/routes/admin/audit';
import adminContent from '@/routes/admin/content';
import adminReferenceData from '@/routes/admin/reference-data';
import adminSettings from '@/routes/admin/settings';
import adminStaff from '@/routes/admin/staff';
import adminDisputes from '@/routes/admin/disputes';
import adminOrders from '@/routes/admin/orders';
import adminMechanics from '@/routes/admin/mechanics';
import adminRatings from '@/routes/admin/ratings';
import adminSellers from '@/routes/admin/sellers';
import adminTrust from '@/routes/admin/trust';
import adminUsers from '@/routes/admin/users';
import { dashboard as financeDashboard } from '@/routes/admin/finance';
import financeAdjustments from '@/routes/admin/finance/adjustments';
import financeExports from '@/routes/admin/finance/exports';
import financeStatements from '@/routes/admin/finance/statements';
import financeVat from '@/routes/admin/finance/vat';
import financeLedger from '@/routes/admin/finance/ledger';
import financePolicies from '@/routes/admin/finance/policies';
import type { NavItem } from '@/types';

/**
 * Staff console navigation. Items whose routes do not exist yet are added by
 * the module that owns them.
 */
const navItems: NavItem[] = [
    { title: 'Dashboard', href: admin.dashboard(), icon: LayoutGrid },
    { title: 'Accounts', href: adminUsers.index(), icon: Users },
    { title: 'Sellers', href: adminSellers.index(), icon: BadgeCheck },
    /* The mechanic approval queue: nothing is public until it clears this. */
    { title: 'Mechanics', href: adminMechanics.index(), icon: Wrench },
    { title: 'Listings', href: adminListings.index(), icon: ShieldCheck },
    { title: 'Orders', href: adminOrders.index(), icon: Receipt },
    /* Money and an order both stop moving until each of these is decided. */
    { title: 'Disputes', href: adminDisputes.index(), icon: Scale },
    /* Reviews the screen held back, and reviews somebody objected to. */
    {
        title: 'Review queue',
        href: adminRatings.index(),
        icon: MessageSquareWarning,
    },
    /* Shops whose reputation says somebody should be talking to them. */
    { title: 'Seller trust', href: adminTrust.index(), icon: Star },
    /* Every curated list in one place, with the merge tool for duplicates. */
    {
        title: 'Reference data',
        href: adminReferenceData.index(),
        icon: SlidersHorizontal,
    },
    /* Shops that have stopped vouching for what is on their shelves. */
    { title: 'Stale stock', href: staleStock(), icon: PackageSearch },
    /* The reference-data backlog, written by buyers who found nothing. */
    { title: 'Search insights', href: searchInsights(), icon: SearchX },
    /*
     * What the platform sends and over what. Here rather than under finance
     * because it is a product setting, but it is the lever that stops an SMS
     * bill, so it is one click from the dashboard.
     */
    {
        title: 'Notification routing',
        href: admin.notifications.matrix.edit(),
        icon: BellRing,
    },
];

/*
 * Money. Gated by policy behind these routes rather than hidden here — a
 * moderator following a link gets a 403, not a broken page.
 */
const financeItems: NavItem[] = [
    { title: 'Finance', href: financeDashboard.url(), icon: TrendingUp },
    { title: 'Ledger', href: financeLedger.index(), icon: Banknote },
    { title: 'Statements', href: financeStatements.index(), icon: FileText },
    { title: 'Policies', href: financePolicies.index(), icon: Percent },
    { title: 'Adjustments', href: financeAdjustments.index(), icon: Scale },
    { title: 'VAT', href: financeVat.index(), icon: Landmark },
    { title: 'Exports', href: financeExports.index(), icon: Download },
];

/*
 * The platform's own configuration, and the people who may change it. Gated
 * by policy behind the routes rather than hidden here — a moderator following
 * a link gets a 403, not a broken page — with the sole exception that these
 * two are administrator-only in their entirety, so showing them to everybody
 * would put two dead ends in every moderator's sidebar.
 */
const platformItems: NavItem[] = [
    { title: 'Content', href: adminContent.index(), icon: FileText },
    { title: 'Settings', href: adminSettings.index(), icon: Settings2 },
    { title: 'Staff', href: adminStaff.index(), icon: UserCog },
    { title: 'Audit trail', href: adminAudit.index(), icon: ScrollText },
];

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="admin.dashboard()">
                            <ShieldCheck class="text-primary size-5" />
                            <span class="font-semibold">Staff console</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <SidebarGroup class="px-2 py-0">
                <SidebarGroupLabel>Operations</SidebarGroupLabel>
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

            <SidebarGroup class="px-2 py-0">
                <SidebarGroupLabel>Platform</SidebarGroupLabel>
                <SidebarMenu>
                    <SidebarMenuItem
                        v-for="item in platformItems"
                        :key="item.title"
                    >
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

            <SidebarGroup class="px-2 py-0">
                <SidebarGroupLabel>Money</SidebarGroupLabel>
                <SidebarMenu>
                    <SidebarMenuItem
                        v-for="item in financeItems"
                        :key="item.title"
                    >
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
