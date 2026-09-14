<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ExternalLink, TriangleAlert } from '@lucide/vue';
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
import adminContent from '@/routes/admin/content';
import type { ContentPageVersionSummary } from '@/types';

/**
 * Writing one page.
 *
 * Saving never overwrites: it writes a new version and points the page at it,
 * so the history beside the editor is complete. That matters most for the
 * terms of use, whose version number is copied onto every checkout acceptance
 * — a buyer's record has to keep pointing at the words they actually agreed
 * to, whatever the page says today.
 *
 * The change note is required and is the version's own description in that
 * history. "Corrected the refund window" beside version 4 is what makes the
 * history worth keeping.
 */
const props = defineProps<{
    page: {
        id: number;
        slug: string;
        title: string;
        status: string;
        is_system: boolean;
        is_platform_terms: boolean;
        body: string;
        version: number | null;
        public_href: string | null;
    };
    versions: ContentPageVersionSummary[];
    canPublish: boolean;
    termsNotice: string | null;
}>();

const form = useForm({
    title: props.page.title,
    body: props.page.body,
    change_note: '',
    publish: true,
});

const submit = (): void => {
    form.post(adminContent.publish(props.page.slug).url, {
        preserveScroll: true,
        onSuccess: () => form.reset('change_note'),
    });
};

const when = (value: string | null): string =>
    value ? new Date(value).toLocaleString() : 'Draft';
</script>

<template>
    <Head :title="page.title" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading :title="page.title" :description="`/pages/${page.slug}`" />

            <Button
                v-if="page.public_href"
                as-child
                variant="outline"
                size="sm"
            >
                <Link :href="page.public_href">
                    View live
                    <ExternalLink class="size-3.5" />
                </Link>
            </Button>
        </div>

        <div
            v-if="termsNotice"
            class="border-destructive/40 flex items-start gap-2 rounded-lg border p-3 text-sm"
        >
            <TriangleAlert class="text-destructive mt-0.5 size-4 shrink-0" />
            <p>{{ termsNotice }}</p>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle>
                        Version {{ (page.version ?? 0) + 1 }} (next)
                    </CardTitle>
                    <CardDescription>
                        Saving writes a new version. Nothing is overwritten.
                    </CardDescription>
                </CardHeader>

                <CardContent class="space-y-4">
                    <div class="space-y-1.5">
                        <Label for="title">Title</Label>
                        <Input
                            id="title"
                            v-model="form.title"
                            :disabled="!canPublish"
                        />
                        <InputError :message="form.errors.title" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="body">Body</Label>
                        <textarea
                            id="body"
                            v-model="form.body"
                            rows="18"
                            :disabled="!canPublish"
                            class="border-input bg-background w-full rounded-md border p-3 font-mono text-sm"
                        />
                        <InputError :message="form.errors.body" />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="change_note">What changed, and why?</Label>
                        <Input
                            id="change_note"
                            v-model="form.change_note"
                            :disabled="!canPublish"
                            placeholder="Kept against this version, and written to the audit trail."
                        />
                        <InputError :message="form.errors.change_note" />
                    </div>

                    <div v-if="canPublish" class="flex justify-end gap-2">
                        <Button
                            variant="outline"
                            :disabled="form.processing"
                            @click="
                                form.publish = false;
                                submit();
                            "
                        >
                            Save as draft
                        </Button>
                        <Button
                            :disabled="form.processing"
                            @click="
                                form.publish = true;
                                submit();
                            "
                        >
                            <Spinner v-if="form.processing" />
                            Publish
                        </Button>
                    </div>

                    <p v-else class="text-muted-foreground text-sm">
                        This page is published by a platform administrator.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>History</CardTitle>
                    <CardDescription>
                        Every version this page has ever had.
                    </CardDescription>
                </CardHeader>

                <CardContent class="space-y-3">
                    <div
                        v-for="version in versions"
                        :key="version.id"
                        class="rounded-lg border p-3 text-sm"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-medium">
                                Version {{ version.version }}
                            </span>
                            <Badge v-if="version.is_current">Current</Badge>
                        </div>
                        <p class="text-muted-foreground mt-1 text-xs">
                            {{ when(version.published_at) }}
                            <template v-if="version.author">
                                · {{ version.author }}
                            </template>
                        </p>
                        <p v-if="version.change_note" class="mt-1 text-xs">
                            {{ version.change_note }}
                        </p>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
