<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download, ShieldAlert } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import financeExports from '@/routes/admin/finance/exports';

/**
 * The files the client's accountant closes a period from.
 *
 * There is no accounting integration behind MonaFind and none planned, so
 * these downloads are the hand-off rather than a convenience. The warning is
 * there because each file is a list of names, addresses and money movements
 * leaving the platform — and because every download writes an audit row, so
 * the person clicking should know it is recorded.
 *
 * A download is a plain navigation rather than an Inertia visit: the response
 * is a file, not a page, and routing it through the SPA router would leave
 * the visit hanging.
 */
const props = defineProps<{
    exports: { value: string; label: string; description: string }[];
    period: { from: string; to: string; label: string };
}>();

const from = ref(props.period.from);
const to = ref(props.period.to);

const download = (type: string, format: 'csv' | 'xlsx'): void => {
    /* A plain navigation: the response is a file, and an Inertia visit would hang on it. */
    globalThis.location.href = financeExports.download.url(type, {
        query: { from: from.value, to: to.value, format },
    });
};
</script>

<template>
    <Head title="Finance exports" />

    <div class="space-y-6 p-4">
        <Heading
            title="Exports"
            description="The hand-off to the accountant. MonaFind pushes nothing to an external system — these files are the interface."
        />

        <Alert>
            <ShieldAlert class="size-4" />
            <AlertTitle>Every download is recorded</AlertTitle>
            <AlertDescription>
                These files contain names, addresses and money movements, and
                they leave the platform when you download them. Who took which
                export, for which period, is written to the audit trail.
            </AlertDescription>
        </Alert>

        <Card>
            <CardHeader>
                <h2 class="font-semibold">Period</h2>
                <p class="text-muted-foreground text-sm">
                    Orders are bounded by when they were paid, payments by when
                    the state was observed, journal lines by when they were
                    posted.
                </p>
            </CardHeader>
            <CardContent class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="space-y-1.5">
                    <Label for="from">From</Label>
                    <Input id="from" v-model="from" type="date" />
                </div>
                <div class="space-y-1.5">
                    <Label for="to">To</Label>
                    <Input id="to" v-model="to" type="date" />
                </div>
            </CardContent>
        </Card>

        <div class="grid gap-4 sm:grid-cols-2">
            <Card v-for="item in exports" :key="item.value">
                <CardHeader>
                    <h3 class="font-semibold">{{ item.label }}</h3>
                    <p class="text-muted-foreground text-sm">
                        {{ item.description }}
                    </p>
                </CardHeader>
                <CardContent class="flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="download(item.value, 'csv')"
                    >
                        <Download class="size-4" />
                        CSV
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="download(item.value, 'xlsx')"
                    >
                        <Download class="size-4" />
                        XLSX
                    </Button>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
