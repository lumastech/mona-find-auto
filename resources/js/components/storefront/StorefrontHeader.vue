<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Heart, LogIn, Menu, ShoppingCart, Store, Wrench } from '@lucide/vue';
import { computed } from 'vue';
import NotificationBell from '@/components/messaging/NotificationBell.vue';
import CategoryMegaMenu from '@/components/storefront/CategoryMegaMenu.vue';
import SearchBar from '@/components/storefront/SearchBar.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { getInitials } from '@/composables/useInitials';
import admin from '@/routes/admin';
import cartRoutes from '@/routes/cart';
import categories from '@/routes/categories';
import { home, login, register, wishlist } from '@/routes';
import mechanics from '@/routes/mechanics';
import orders from '@/routes/orders';
import quotations from '@/routes/quotations';
import seller from '@/routes/seller';
import sellers from '@/routes/sellers';
import threads from '@/routes/threads';

/**
 * The storefront header: search, browse, wishlist, cart and the account menu.
 *
 * Navy, because the masthead is where the brand lives and the catalogue below
 * it should be the only thing on the page competing for attention. Everything
 * inside it takes its colour from --brand-navy-foreground rather than the
 * page's own --foreground, which is navy and would vanish.
 *
 * Mobile-first — on a narrow screen the search bar drops onto its own row and
 * the secondary links move into a sheet.
 *
 * The two badges come from the shared `shopping` prop rather than from each
 * page, because the header is on every screen: a count that is only right on
 * the pages that remembered to pass it is a count nobody believes. The props
 * remain as an override for a page that has a better answer.
 */
const props = defineProps<{
    cartCount?: number;
    wishlistCount?: number;
}>();

const page = usePage();
const auth = computed(() => page.props.auth);

const cartCount = computed(
    () => props.cartCount ?? page.props.shopping?.cart_count ?? 0,
);

const wishlistCount = computed(
    () => props.wishlistCount ?? page.props.shopping?.wishlist_count ?? 0,
);

/** Ghost buttons sit on navy here, so they cannot use the page's accent. */
const onNavy =
    'text-brand-navy-foreground hover:bg-white/10 hover:text-white focus-visible:ring-brand-gold';
</script>

<template>
    <header class="bg-brand-navy text-brand-navy-foreground sticky top-0 z-40">
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <div class="flex h-16 items-center gap-3">
                <Sheet>
                    <SheetTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            :class="['md:hidden', onNavy]"
                            aria-label="Open menu"
                        >
                            <Menu class="size-5" />
                        </Button>
                    </SheetTrigger>
                    <SheetContent side="left" class="w-72 p-6">
                        <SheetHeader class="p-0">
                            <SheetTitle class="font-display">
                                MonaFindAuto
                            </SheetTitle>
                        </SheetHeader>
                        <nav class="mt-6 grid gap-1 text-sm">
                            <Link
                                :href="categories.index()"
                                class="hover:bg-accent rounded-md px-2 py-2"
                            >
                                Browse parts
                            </Link>
                            <Link
                                :href="mechanics.index()"
                                class="hover:bg-accent rounded-md px-2 py-2"
                            >
                                Find a mechanic
                            </Link>
                            <Link
                                :href="wishlist()"
                                class="hover:bg-accent rounded-md px-2 py-2"
                            >
                                Wishlist
                            </Link>
                            <Link
                                :href="cartRoutes.index()"
                                class="hover:bg-accent rounded-md px-2 py-2"
                            >
                                Cart
                            </Link>
                            <Link
                                v-if="auth.user"
                                :href="quotations.index()"
                                class="hover:bg-accent rounded-md px-2 py-2"
                            >
                                Your quotes
                            </Link>
                            <Link
                                v-if="auth.user"
                                :href="orders.index()"
                                class="hover:bg-accent rounded-md px-2 py-2"
                            >
                                Your orders
                            </Link>
                            <Link
                                v-if="auth.user"
                                :href="threads.index()"
                                class="hover:bg-accent rounded-md px-2 py-2"
                            >
                                Messages
                            </Link>
                            <Link
                                v-if="auth.isSeller"
                                :href="seller.dashboard()"
                                class="hover:bg-accent rounded-md px-2 py-2"
                            >
                                Seller portal
                            </Link>
                            <Link
                                v-else
                                :href="sellers.register()"
                                class="hover:bg-accent rounded-md px-2 py-2"
                            >
                                Sell on MonaFindAuto
                            </Link>
                            <Link
                                v-if="auth.isStaff"
                                :href="admin.dashboard()"
                                class="hover:bg-accent rounded-md px-2 py-2"
                            >
                                Staff console
                            </Link>
                        </nav>
                    </SheetContent>
                </Sheet>

                <Link
                    :href="home()"
                    class="focus-visible:ring-brand-gold flex items-center gap-2 rounded-md focus-visible:ring-2 focus-visible:outline-none"
                >
                    <span
                        class="bg-brand-gold font-display text-brand-navy grid size-8 shrink-0 place-items-center rounded-md text-base font-bold"
                        aria-hidden="true"
                    >
                        M
                    </span>
                    <span
                        class="font-display hidden text-lg font-semibold tracking-tight sm:inline"
                    >
                        MonaFind<span class="text-brand-gold">Auto</span>
                    </span>
                    <span class="sr-only sm:hidden">MonaFindAuto home</span>
                </Link>

                <div class="ml-2 hidden flex-1 md:block">
                    <SearchBar tone="navy" />
                </div>

                <div class="ml-auto flex items-center gap-1">
                    <!-- Null for a guest, so nothing is drawn. -->
                    <NotificationBell />

                    <Button
                        variant="ghost"
                        size="icon"
                        as-child
                        :class="['relative', onNavy]"
                    >
                        <Link :href="wishlist()" aria-label="Wishlist">
                            <Heart class="size-5" />
                            <Badge
                                v-if="wishlistCount > 0"
                                class="bg-brand-gold text-brand-navy absolute -top-0.5 -right-0.5 h-5 min-w-5 justify-center rounded-full px-1 text-[10px]"
                            >
                                {{ wishlistCount }}
                            </Badge>
                        </Link>
                    </Button>

                    <Button
                        variant="ghost"
                        size="icon"
                        as-child
                        :class="['relative', onNavy]"
                    >
                        <Link :href="cartRoutes.index()" aria-label="Cart">
                            <ShoppingCart class="size-5" />
                            <Badge
                                v-if="cartCount > 0"
                                class="bg-brand-gold text-brand-navy absolute -top-0.5 -right-0.5 h-5 min-w-5 justify-center rounded-full px-1 text-[10px]"
                            >
                                {{ cartCount }}
                            </Badge>
                        </Link>
                    </Button>

                    <DropdownMenu v-if="auth.user">
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                :class="['rounded-full', onNavy]"
                                aria-label="Account menu"
                            >
                                <Avatar class="size-8">
                                    <AvatarFallback
                                        class="bg-white/15 text-xs text-white"
                                    >
                                        {{ getInitials(auth.user.name) }}
                                    </AvatarFallback>
                                </Avatar>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent class="w-56" align="end">
                            <UserMenuContent :user="auth.user" />
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <template v-else>
                        <Button
                            variant="ghost"
                            size="sm"
                            as-child
                            :class="onNavy"
                        >
                            <Link :href="login()">
                                <LogIn class="size-4 sm:hidden" />
                                <span class="hidden sm:inline">Log in</span>
                            </Link>
                        </Button>
                        <Button
                            size="sm"
                            as-child
                            class="bg-brand-gold text-brand-navy hidden hover:bg-[#d4b972] sm:inline-flex"
                        >
                            <Link :href="register()">Sign up</Link>
                        </Button>
                    </template>
                </div>
            </div>

            <!--
                The browse row. Desktop only: on a phone these live in the
                sheet, and a second row of links would push the catalogue
                below the fold.
            -->
            <nav
                class="hidden h-11 items-center gap-1 border-t border-white/10 md:flex"
                aria-label="Catalogue"
            >
                <CategoryMegaMenu />
                <Link
                    :href="mechanics.index()"
                    class="flex h-9 items-center gap-1.5 rounded-md px-3 text-sm font-medium hover:bg-white/10"
                >
                    <Wrench class="size-4" aria-hidden="true" />
                    Find a mechanic
                </Link>
                <Link
                    :href="
                        auth.isSeller ? seller.dashboard() : sellers.register()
                    "
                    class="ml-auto flex h-9 items-center gap-1.5 rounded-md px-3 text-sm font-medium hover:bg-white/10"
                >
                    <Store class="size-4" aria-hidden="true" />
                    {{
                        auth.isSeller ? 'Seller portal' : 'Sell on MonaFindAuto'
                    }}
                </Link>
            </nav>

            <div class="pb-3 md:hidden">
                <SearchBar tone="navy" placeholder="Search parts…" />
            </div>
        </div>
    </header>
</template>
