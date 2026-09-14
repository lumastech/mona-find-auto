<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { FileCheck2, Trash2, Upload } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import seller from '@/routes/seller';
import sellers from '@/routes/sellers';
import type { SellerDocument } from '@/types';

/**
 * Step five: the paperwork.
 *
 * Each document type gets its own upload rather than one "attach files" box,
 * because a reviewer with five unlabelled PDFs cannot tell which is the
 * certificate and which is the ID. Re-uploading a type replaces it, since
 * that is almost always a correction.
 */
withDefaults(
    defineProps<{
        documents: SellerDocument[];
        canContinue: boolean;
        /** Set on the portal page, where there is no next step to move to. */
        standalone?: boolean;
    }>(),
    { standalone: false },
);
</script>

<template>
    <div class="space-y-6">
        <p class="text-muted-foreground text-sm">
            Photograph or scan each document. Only MonaFind reviewers can open
            these — they are never shown to buyers.
        </p>

        <div
            v-for="document in documents"
            :key="document.value"
            class="space-y-3 rounded-lg border p-4"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2 font-medium">
                        {{ document.label }}
                        <Badge v-if="document.required" variant="secondary">
                            Required
                        </Badge>
                        <Badge v-if="document.uploaded" variant="outline">
                            <FileCheck2 aria-hidden="true" />
                            Uploaded
                        </Badge>
                    </p>
                    <p class="text-muted-foreground text-sm">
                        {{ document.guidance }}
                    </p>
                </div>

                <Link
                    v-if="document.uploaded && document.media_id"
                    :href="seller.documents.destroy(document.media_id)"
                    method="delete"
                    as="button"
                    class="text-muted-foreground hover:text-destructive"
                    :aria-label="`Remove ${document.label}`"
                >
                    <Trash2 class="size-4" aria-hidden="true" />
                </Link>
            </div>

            <p v-if="document.uploaded" class="text-muted-foreground text-sm">
                {{ document.file_name }}
            </p>

            <Form
                v-else
                v-bind="seller.documents.store.form()"
                v-slot="{ errors, processing }"
                class="flex flex-wrap items-center gap-3"
            >
                <input
                    type="hidden"
                    name="document_type"
                    :value="document.value"
                />
                <input
                    type="file"
                    name="file"
                    required
                    accept=".pdf,.jpg,.jpeg,.png,.webp"
                    class="text-muted-foreground file:bg-secondary file:text-secondary-foreground min-w-0 flex-1 text-sm file:mr-3 file:rounded-md file:border-0 file:px-3 file:py-1.5 file:text-sm"
                />

                <Button type="submit" size="sm" :disabled="processing">
                    <Spinner v-if="processing" class="size-4" />
                    <Upload v-else class="size-4" aria-hidden="true" />
                    Upload
                </Button>

                <InputError class="w-full" :message="errors.file" />
            </Form>
        </div>

        <template v-if="!standalone">
            <Link
                v-if="canContinue"
                :href="sellers.register.step({ step: 'review' })"
                class="inline-flex"
            >
                <Button>Continue to review</Button>
            </Link>
            <p v-else class="text-muted-foreground text-sm">
                Upload the required documents to carry on.
            </p>
        </template>
    </div>
</template>
