<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

/**
 * One of the pages MonaFind publishes about itself.
 *
 * The body is plain text with blank lines between paragraphs and `**bold**`
 * for headings, rendered here rather than stored as HTML. Storing HTML would
 * mean a staff account that could write a script tag onto the storefront, and
 * the pages are prose — nothing on them needs more than this.
 */
const props = defineProps<{
    page: {
        slug: string;
        title: string;
        body: string;
        meta_description: string | null;
        version: number | null;
        updated_at: string | null;
    };
}>();

/** Paragraph blocks, with the `**lead**` of each pulled out as a heading. */
const blocks = props.page.body
    .split(/\n\s*\n/)
    .map((block) => block.trim())
    .filter((block) => block.length > 0)
    .map((block) => {
        const match = block.match(/^\*\*(.+?)\*\*\s*([\s\S]*)$/);

        return match
            ? { heading: match[1], text: match[2].trim() }
            : { heading: null, text: block };
    });

const updated = props.page.updated_at
    ? new Date(props.page.updated_at).toLocaleDateString()
    : null;
</script>

<template>
    <Head :title="page.title">
        <meta
            v-if="page.meta_description"
            name="description"
            :content="page.meta_description"
        />
    </Head>

    <article class="mx-auto max-w-3xl px-4 py-10">
        <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">
            {{ page.title }}
        </h1>

        <p v-if="updated" class="text-muted-foreground mt-2 text-sm">
            Last updated {{ updated }}
            <template v-if="page.version">
                · version {{ page.version }}
            </template>
        </p>

        <div class="mt-8 space-y-6">
            <section v-for="(block, index) in blocks" :key="index">
                <h2 v-if="block.heading" class="text-base font-semibold">
                    {{ block.heading }}
                </h2>
                <p
                    v-if="block.text"
                    class="text-muted-foreground mt-1 leading-relaxed whitespace-pre-line"
                >
                    {{ block.text }}
                </p>
            </section>
        </div>
    </article>
</template>
