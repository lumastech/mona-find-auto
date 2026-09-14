<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Info, TriangleAlert, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { AnnouncementBanner } from '@/types';

/**
 * The banner staff scheduled for whichever area this page is in.
 *
 * Which banners reach this page is decided on the server — an announcement
 * for sellers never arrives in a buyer's props at all — so this component
 * renders whatever it is given.
 *
 * Dismissals are per browser and per banner id, kept in localStorage rather
 * than on the account: a banner saying delivery to Solwezi is suspended does
 * not need a database write per reader. Critical banners cannot be dismissed;
 * they are up because something is actually wrong.
 */
const page = usePage();

const STORAGE_KEY = 'monafind.announcements.dismissed';

const dismissed = ref<number[]>(read());

function read(): number[] {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);

        return raw ? (JSON.parse(raw) as number[]) : [];
    } catch {
        /* Private browsing, or site data blocked. Show the banner. */
        return [];
    }
}

const banners = computed(() =>
    (
        (page.props.announcements as AnnouncementBanner[] | undefined) ?? []
    ).filter((banner) => !dismissed.value.includes(banner.id)),
);

const dismiss = (banner: AnnouncementBanner): void => {
    dismissed.value = [...dismissed.value, banner.id];

    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(dismissed.value));
    } catch {
        /* Nothing to do; it simply comes back on the next page. */
    }
};

const classesFor = (banner: AnnouncementBanner): string =>
    ({
        info: 'bg-muted text-foreground',
        warning:
            'bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-100',
        critical: 'bg-destructive text-destructive-foreground',
    })[banner.level];
</script>

<template>
    <div v-if="banners.length > 0">
        <div
            v-for="banner in banners"
            :key="banner.id"
            class="flex items-start gap-3 px-4 py-2.5 text-sm"
            :class="classesFor(banner)"
            role="status"
        >
            <component
                :is="banner.level === 'info' ? Info : TriangleAlert"
                class="mt-0.5 size-4 shrink-0"
            />

            <div class="min-w-0 flex-1">
                <p class="font-medium">{{ banner.title }}</p>
                <p class="opacity-90">{{ banner.body }}</p>
                <a
                    v-if="banner.link_url"
                    :href="banner.link_url"
                    class="mt-0.5 inline-block underline underline-offset-4"
                >
                    {{ banner.link_label ?? 'Read more' }}
                </a>
            </div>

            <button
                v-if="banner.dismissible"
                type="button"
                class="shrink-0 rounded p-1 opacity-70 hover:opacity-100"
                :aria-label="`Dismiss: ${banner.title}`"
                @click="dismiss(banner)"
            >
                <X class="size-4" />
            </button>
        </div>
    </div>
</template>
