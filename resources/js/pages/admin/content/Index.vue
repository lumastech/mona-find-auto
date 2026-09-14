<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Megaphone, Plus } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import adminAnnouncements from '@/routes/admin/announcements';
import type { AnnouncementSummary, ContentPageSummary } from '@/types';

/**
 * What MonaFind is telling people right now.
 *
 * Pages and banners share a screen because they are the same job — splitting
 * them would put the banner announcing a change on a different screen from
 * the page describing it.
 */
defineProps<{
    pages: ContentPageSummary[];
    announcements: AnnouncementSummary[];
    announcementOptions: {
        levels: { value: string; label: string }[];
        audiences: { value: string; label: string }[];
    };
    canManageAnnouncements: boolean;
}>();

const composing = ref(false);

const form = useForm({
    title: '',
    body: '',
    level: 'info',
    audience: 'everyone',
    link_url: '',
    link_label: '',
    starts_at: new Date().toISOString().slice(0, 16),
    ends_at: '',
    is_active: true,
});

const schedule = (): void => {
    form.post(adminAnnouncements.store().url, {
        preserveScroll: true,
        onSuccess: () => {
            composing.value = false;
            form.reset();
        },
    });
};

const takeDown = (announcement: AnnouncementSummary): void => {
    useForm({}).post(adminAnnouncements.deactivate(announcement.id).url, {
        preserveScroll: true,
    });
};

const when = (value: string | null): string =>
    value ? new Date(value).toLocaleString() : '—';
</script>

<template>
    <Head title="Content and announcements" />

    <div class="space-y-6 p-4">
        <Heading
            title="Content and announcements"
            description="The pages MonaFind publishes about itself, and the banner across the top of each area."
        />

        <Card>
            <CardHeader>
                <CardTitle>Pages</CardTitle>
                <CardDescription>
                    Every edit writes a new version and keeps the old one.
                    Publishing the platform terms bumps the version number
                    recorded against every checkout acceptance.
                </CardDescription>
            </CardHeader>

            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-muted-foreground text-left">
                        <tr class="border-b">
                            <th class="py-2 pr-3 font-medium">Page</th>
                            <th class="py-2 pr-3 font-medium">Status</th>
                            <th class="py-2 pr-3 font-medium">Version</th>
                            <th class="py-2 pr-3 font-medium">Published</th>
                            <th class="py-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="page in pages"
                            :key="page.id"
                            class="border-b last:border-0"
                        >
                            <td class="py-2 pr-3">
                                <p class="font-medium">{{ page.title }}</p>
                                <p class="text-muted-foreground text-xs">
                                    /pages/{{ page.slug }}
                                    <Badge
                                        v-if="page.is_platform_terms"
                                        variant="outline"
                                        class="ml-1"
                                    >
                                        Feeds checkout
                                    </Badge>
                                </p>
                            </td>
                            <td class="py-2 pr-3">
                                <Badge
                                    :variant="
                                        page.status === 'published'
                                            ? 'default'
                                            : 'secondary'
                                    "
                                >
                                    {{ page.status_label }}
                                </Badge>
                            </td>
                            <td class="py-2 pr-3 tabular-nums">
                                {{ page.version ?? '—' }}
                            </td>
                            <td class="text-muted-foreground py-2 pr-3">
                                {{ when(page.published_at) }}
                            </td>
                            <td class="py-2 text-right">
                                <Button as-child size="sm" variant="ghost">
                                    <Link :href="page.href">
                                        {{ page.can_publish ? 'Edit' : 'View' }}
                                    </Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <CardTitle class="flex items-center gap-2">
                            <Megaphone class="size-4" />
                            Announcements
                        </CardTitle>
                        <CardDescription>
                            Scheduled banners. One with a start time appears on
                            its own — nobody has to be awake for it.
                        </CardDescription>
                    </div>

                    <Button
                        v-if="canManageAnnouncements"
                        size="sm"
                        @click="composing = !composing"
                    >
                        <Plus class="size-4" />
                        Schedule one
                    </Button>
                </div>
            </CardHeader>

            <CardContent class="space-y-4">
                <form
                    v-if="composing"
                    class="grid gap-3 rounded-lg border p-3 sm:grid-cols-2"
                    @submit.prevent="schedule"
                >
                    <div class="space-y-1.5 sm:col-span-2">
                        <Label for="a-title">Title</Label>
                        <Input id="a-title" v-model="form.title" />
                        <InputError :message="form.errors.title" />
                    </div>

                    <div class="space-y-1.5 sm:col-span-2">
                        <Label for="a-body">Message</Label>
                        <textarea
                            id="a-body"
                            v-model="form.body"
                            rows="3"
                            class="border-input bg-background w-full rounded-md border p-3 text-sm"
                        />
                        <InputError :message="form.errors.body" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="a-level">Level</Label>
                        <select
                            id="a-level"
                            v-model="form.level"
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        >
                            <option
                                v-for="level in announcementOptions.levels"
                                :key="level.value"
                                :value="level.value"
                            >
                                {{ level.label }}
                            </option>
                        </select>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="a-audience">Shown to</Label>
                        <select
                            id="a-audience"
                            v-model="form.audience"
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        >
                            <option
                                v-for="audience in announcementOptions.audiences"
                                :key="audience.value"
                                :value="audience.value"
                            >
                                {{ audience.label }}
                            </option>
                        </select>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="a-start">Starts</Label>
                        <Input
                            id="a-start"
                            v-model="form.starts_at"
                            type="datetime-local"
                        />
                        <InputError :message="form.errors.starts_at" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="a-end">Ends (optional)</Label>
                        <Input
                            id="a-end"
                            v-model="form.ends_at"
                            type="datetime-local"
                        />
                        <InputError :message="form.errors.ends_at" />
                    </div>

                    <div class="flex justify-end gap-2 sm:col-span-2">
                        <Button variant="ghost" @click="composing = false">
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            <Spinner v-if="form.processing" />
                            Schedule
                        </Button>
                    </div>
                </form>

                <p
                    v-if="announcements.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    Nothing scheduled.
                </p>

                <div
                    v-for="announcement in announcements"
                    :key="announcement.id"
                    class="flex flex-wrap items-start justify-between gap-3 rounded-lg border p-3"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-medium">{{ announcement.title }}</p>
                            <Badge
                                :variant="
                                    announcement.level === 'critical'
                                        ? 'destructive'
                                        : 'secondary'
                                "
                            >
                                {{ announcement.level_label }}
                            </Badge>
                            <Badge v-if="announcement.is_showing">
                                Showing now
                            </Badge>
                            <Badge
                                v-else-if="announcement.has_ended"
                                variant="outline"
                            >
                                Finished
                            </Badge>
                            <Badge v-else variant="outline">Scheduled</Badge>
                        </div>
                        <p class="text-muted-foreground mt-1 text-sm">
                            {{ announcement.body }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            {{ announcement.audience_label }} ·
                            {{ when(announcement.starts_at) }} →
                            {{
                                announcement.ends_at
                                    ? when(announcement.ends_at)
                                    : 'until switched off'
                            }}
                        </p>
                    </div>

                    <Button
                        v-if="canManageAnnouncements && announcement.is_active"
                        size="sm"
                        variant="outline"
                        @click="takeDown(announcement)"
                    >
                        Take down
                    </Button>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
