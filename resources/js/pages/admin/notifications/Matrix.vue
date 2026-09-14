<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Lock, RotateCcw, TriangleAlert } from '@lucide/vue';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import admin from '@/routes/admin';
import type { MatrixChannel, MatrixGroup } from '@/types/messaging';

/**
 * Which notifications go out over which channels, platform-wide.
 *
 * The screen exists for one operational reason above all: SMS costs money per
 * message and the bill arrives after the fact. Unchecking an SMS box here
 * stops those texts everywhere, immediately, with no deployment.
 *
 * Mandatory rows are rendered as locked rather than as checkboxes. A grid
 * that let somebody uncheck verification codes would be a grid that locked
 * every account out of the platform between a click and a rollback.
 *
 * The reason field is required because this is the setting somebody will ask
 * about in three months: it goes on the audit row with the change.
 */
const props = defineProps<{
    matrix: Record<string, string[]>;
    defaults: Record<string, string[]>;
    channels: MatrixChannel[];
    groups: MatrixGroup[];
}>();

const state = reactive<Record<string, string[]>>(
    Object.fromEntries(
        Object.entries(props.matrix).map(([event, channels]) => [
            event,
            [...channels],
        ]),
    ),
);

const form = useForm({ matrix: state, reason: '' });

const isOn = (event: string, channel: string): boolean =>
    (state[event] ?? []).includes(channel);

const toggle = (event: string, channel: string, on: boolean): void => {
    const current = new Set(state[event] ?? []);

    if (on) {
        current.add(channel);
    } else {
        current.delete(channel);
    }

    state[event] = [...current];
};

const restoreDefaults = (): void => {
    for (const [event, channels] of Object.entries(props.defaults)) {
        state[event] = [...channels];
    }
};

const save = (): void => {
    form.transform((data) => ({ ...data, matrix: state })).put(
        admin.notifications.matrix.update.url(),
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head title="Notification routing" />

    <form class="space-y-6 p-4" @submit.prevent="save">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                variant="small"
                title="Notification routing"
                description="Which channels each kind of notification uses. This is a ceiling: a person can narrow it further in their own settings, and can never widen it."
            />

            <Button
                type="button"
                variant="outline"
                size="sm"
                class="gap-1.5"
                @click="restoreDefaults"
            >
                <RotateCcw class="size-4" />
                Restore defaults
            </Button>
        </header>

        <p
            class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            SMS is charged per message. Switching a row off here stops those
            texts for everybody, straight away.
        </p>

        <section v-for="group in groups" :key="group.group" class="space-y-2">
            <h2 class="text-sm font-semibold tracking-tight">
                {{ group.group }}
            </h2>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full min-w-125 text-sm">
                    <thead class="bg-muted/50">
                        <tr>
                            <th
                                scope="col"
                                class="px-4 py-2 text-left font-medium"
                            >
                                Notification
                            </th>
                            <th
                                v-for="channel in channels"
                                :key="channel.value"
                                scope="col"
                                class="w-28 px-4 py-2 text-center font-medium"
                                :title="channel.description"
                            >
                                {{ channel.label }}
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y">
                        <tr v-for="event in group.events" :key="event.event">
                            <th
                                scope="row"
                                class="px-4 py-3 text-left font-normal"
                            >
                                <span class="block font-medium">
                                    {{ event.label }}
                                </span>
                                <span class="text-muted-foreground text-xs">
                                    {{ event.description }}
                                </span>
                            </th>

                            <td
                                v-for="channel in channels"
                                :key="channel.value"
                                class="px-4 py-3 text-center"
                            >
                                <Lock
                                    v-if="event.mandatory"
                                    class="text-muted-foreground mx-auto size-4"
                                    aria-label="Always sent; not configurable"
                                />
                                <Checkbox
                                    v-else
                                    :model-value="
                                        isOn(event.event, channel.value)
                                    "
                                    :aria-label="`${event.label} by ${channel.label}`"
                                    @update:model-value="
                                        (value: boolean | 'indeterminate') =>
                                            toggle(
                                                event.event,
                                                channel.value,
                                                value === true,
                                            )
                                    "
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="max-w-xl space-y-2">
            <Label for="reason">Why is this changing?</Label>
            <Input
                id="reason"
                v-model="form.reason"
                placeholder="e.g. SMS spend is over budget this month"
                required
            />
            <p class="text-muted-foreground text-xs">
                Recorded on the audit trail with your name.
            </p>
            <InputError :message="form.errors.reason" />
        </div>

        <Button type="submit" :disabled="form.processing">
            Save routing
        </Button>
    </form>
</template>
