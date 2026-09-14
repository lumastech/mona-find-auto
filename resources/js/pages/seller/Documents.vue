<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import StepDocuments from '@/components/seller/wizard/StepDocuments.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import type { SellerDocument } from '@/types';

/**
 * A seller's verification documents.
 *
 * The same list the sign-up wizard shows, so a seller who is asked for a
 * missing certificate after review can find it where they left it.
 */
defineProps<{
    documents: SellerDocument[];
    missing: string[];
}>();
</script>

<template>
    <Head title="Documents" />

    <div class="max-w-3xl space-y-6 p-4">
        <Heading
            title="Documents"
            description="Only MonaFind reviewers can open these. They are never shown to buyers."
        />

        <Alert v-if="missing.length" variant="destructive">
            <AlertTitle>We still need some paperwork</AlertTitle>
            <AlertDescription>
                {{ missing.join(', ') }}
            </AlertDescription>
        </Alert>

        <StepDocuments
            :documents="documents"
            :can-continue="false"
            standalone
        />
    </div>
</template>
