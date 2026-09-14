<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Info } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import adminSettings from '@/routes/admin/settings';
import type { SettingField, SettingPanel, SettingValue } from '@/types';

/**
 * The platform's own numbers.
 *
 * One form per panel rather than one for the whole screen: saving carries a
 * mandatory reason, and a single reason covering "we changed the escrow
 * window and six ranking weights" tells a later reader nothing about either.
 *
 * The range shown beside each field is the same bound the server enforces —
 * both come from SettingsSchema rather than being written twice — so what the
 * form invites and what it will keep cannot drift apart.
 */
const props = defineProps<{
    panels: SettingPanel[];
    readOnlyNote: string;
    termsHref: string;
}>();

/** Only one panel is open, because only one is ever being saved. */
const open = ref<string>(props.panels[0]?.key ?? '');

/**
 * What a form control can actually hold.
 *
 * A day list arrives as an array and is edited as "3, 5", so it is flattened
 * on the way in and parsed back on the way out — the field carries one shape
 * while it is on screen rather than two.
 */
type EditableValue = string | number | boolean;

const asEditable = (field: SettingField): EditableValue =>
    Array.isArray(field.value) ? field.value.join(', ') : field.value;

const forms: Record<string, ReturnType<typeof useForm>> = Object.fromEntries(
    props.panels.map((panel) => [
        panel.key,
        useForm({
            panel: panel.key,
            reason: '',
            values: Object.fromEntries(
                panel.fields.map((field) => [field.key, asEditable(field)]),
            ) as Record<string, EditableValue>,
        }),
    ]),
);

const valuesOf = (panel: string): Record<string, EditableValue> =>
    forms[panel].values as Record<string, EditableValue>;

const read = (panel: string, key: string): EditableValue =>
    valuesOf(panel)[key] ?? '';

/** Text controls all hand back strings; the server casts by declared type. */
const text = (panel: string, key: string): string => String(read(panel, key));

const write = (panel: string, key: string, value: EditableValue): void => {
    valuesOf(panel)[key] = value;
};

const save = (panel: SettingPanel): void => {
    const form = forms[panel.key];

    form.transform((data) => ({
        ...data,
        values: Object.fromEntries(
            panel.fields.map((field): [string, SettingValue] => [
                field.key,
                field.control === 'day-list'
                    ? text(panel.key, field.key)
                          .split(',')
                          .map((day) => Number(day.trim()))
                          .filter((day) => !Number.isNaN(day))
                    : read(panel.key, field.key),
            ]),
        ),
    })).put(adminSettings.update().url, {
        preserveScroll: true,
        onSuccess: () => form.reset('reason'),
    });
};

const errorFor = (
    panel: SettingPanel,
    field: SettingField,
): string | undefined =>
    (forms[panel.key].errors as Record<string, string | undefined>)[
        `values.${field.key}`
    ];

const bounds = (field: SettingField): string | null => {
    if (field.max === null) {
        return null;
    }

    const suffix = field.suffix ? ` ${field.suffix}` : '';

    return `${field.min ?? 0}–${field.max}${suffix}`;
};
</script>

<template>
    <Head title="Platform settings" />

    <div class="space-y-6 p-4">
        <Heading
            title="Platform settings"
            description="The numbers the whole platform runs on. Every change is recorded against your name with the reason you give."
        />

        <div
            class="text-muted-foreground flex items-start gap-2 rounded-lg border p-3 text-sm"
        >
            <Info class="mt-0.5 size-4 shrink-0" />
            <p>
                {{ readOnlyNote }}
                <Link :href="termsHref" class="underline underline-offset-4">
                    Open Content
                </Link>
            </p>
        </div>

        <Card v-for="panel in panels" :key="panel.key">
            <CardHeader
                class="cursor-pointer"
                @click="open = open === panel.key ? '' : panel.key"
            >
                <CardTitle>{{ panel.title }}</CardTitle>
                <CardDescription>{{ panel.description }}</CardDescription>
            </CardHeader>

            <template v-if="open === panel.key">
                <CardContent class="grid gap-5 sm:grid-cols-2">
                    <div
                        v-for="field in panel.fields"
                        :key="field.key"
                        class="space-y-1.5"
                        :class="field.control === 'text' ? 'sm:col-span-2' : ''"
                    >
                        <div class="flex items-baseline justify-between gap-2">
                            <Label :for="field.key">{{ field.label }}</Label>
                            <span
                                v-if="bounds(field)"
                                class="text-muted-foreground text-xs tabular-nums"
                            >
                                {{ bounds(field) }}
                            </span>
                        </div>

                        <label
                            v-if="field.control === 'boolean'"
                            class="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                :id="field.key"
                                :model-value="
                                    Boolean(read(panel.key, field.key))
                                "
                                @update:model-value="
                                    write(panel.key, field.key, Boolean($event))
                                "
                            />
                            <span class="text-muted-foreground">On</span>
                        </label>

                        <select
                            v-else-if="field.control === 'select'"
                            :id="field.key"
                            :value="text(panel.key, field.key)"
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            @change="
                                write(
                                    panel.key,
                                    field.key,
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option
                                v-for="option in field.options ?? []"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>

                        <textarea
                            v-else-if="field.control === 'text'"
                            :id="field.key"
                            :value="text(panel.key, field.key)"
                            rows="3"
                            :maxlength="field.max ?? undefined"
                            class="border-input bg-background w-full rounded-md border p-3 text-sm"
                            @input="
                                write(
                                    panel.key,
                                    field.key,
                                    ($event.target as HTMLTextAreaElement)
                                        .value,
                                )
                            "
                        />

                        <Input
                            v-else
                            :id="field.key"
                            :model-value="text(panel.key, field.key)"
                            :type="
                                field.control === 'integer' ||
                                field.control === 'money'
                                    ? 'number'
                                    : 'text'
                            "
                            :min="field.min ?? undefined"
                            :max="field.max ?? undefined"
                            :inputmode="
                                field.control === 'decimal'
                                    ? 'decimal'
                                    : undefined
                            "
                            @update:model-value="
                                write(panel.key, field.key, String($event))
                            "
                        />

                        <p
                            v-if="field.help"
                            class="text-muted-foreground text-xs"
                        >
                            {{ field.help }}
                        </p>

                        <InputError :message="errorFor(panel, field)" />
                    </div>
                </CardContent>

                <CardFooter class="flex-col items-stretch gap-3 border-t pt-4">
                    <div class="space-y-1.5">
                        <Label :for="`${panel.key}-reason`">
                            Why are you changing this?
                        </Label>
                        <Input
                            :id="`${panel.key}-reason`"
                            v-model="forms[panel.key].reason"
                            placeholder="Recorded in the audit trail against your name."
                        />
                        <InputError :message="forms[panel.key].errors.reason" />
                    </div>

                    <div class="flex justify-end">
                        <Button
                            :disabled="forms[panel.key].processing"
                            @click="save(panel)"
                        >
                            <Spinner v-if="forms[panel.key].processing" />
                            Save
                        </Button>
                    </div>
                </CardFooter>
            </template>
        </Card>
    </div>
</template>
