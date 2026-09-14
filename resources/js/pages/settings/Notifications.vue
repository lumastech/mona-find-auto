<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Lock, Smartphone } from '@lucide/vue';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Separator } from '@/components/ui/separator';
import settings from '@/routes/settings';
import type { PreferenceGroup } from '@/types/messaging';

/**
 * "Tell me about this, by this."
 *
 * Two things this screen is careful about.
 *
 * A switch that does nothing is worse than no switch. Verification codes and
 * suspension notices are sent whatever anybody asks, so they are shown as
 * FIXED rather than as a checkbox that silently ignores the click — somebody
 * who believes they have turned off verification codes and is then surprised
 * by one has been lied to by the interface.
 *
 * And a channel the platform has switched off for everybody is shown as
 * unavailable, with the reason. Greying a box out without saying why invites
 * a support ticket.
 */
const props = defineProps<{
    groups: PreferenceGroup[];
    reachable: { sms: boolean; mail: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Notification settings',
                href: settings.notifications.edit(),
            },
        ],
    },
});

/**
 * The whole screen's state, posted whole.
 *
 * A partial payload cannot tell "left alone" from "switched off", so every
 * switch on the page goes back every time.
 */
const state = reactive<Record<string, Record<string, boolean>>>(
    Object.fromEntries(
        props.groups.flatMap((group) =>
            group.events
                .filter((event) => !event.mandatory)
                .map((event) => [
                    event.event,
                    Object.fromEntries(
                        event.channels
                            .filter((channel) => channel.available)
                            .map((channel) => [
                                channel.channel,
                                channel.enabled,
                            ]),
                    ),
                ]),
        ),
    ),
);

const form = useForm({ preferences: state });

const save = (): void => {
    form.transform(() => ({ preferences: state })).put(
        settings.notifications.update.url(),
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head title="Notification settings" />

    <h1 class="sr-only">Notification settings</h1>

    <form class="space-y-6" @submit.prevent="save">
        <Heading
            variant="small"
            title="Notifications"
            description="Choose what MonaFind tells you about, and how."
        />

        <p
            v-if="!reachable.sms"
            class="text-muted-foreground flex items-start gap-2 rounded-md border px-3 py-2 text-sm"
        >
            <Smartphone class="mt-0.5 size-4 shrink-0" />
            Verify your mobile number to receive text messages. Until then, SMS
            is switched off for your account.
        </p>

        <section v-for="group in groups" :key="group.group" class="space-y-3">
            <h2 class="text-sm font-semibold tracking-tight">
                {{ group.group }}
            </h2>

            <ul class="divide-y rounded-lg border">
                <li
                    v-for="event in group.events"
                    :key="event.event"
                    class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="min-w-0 sm:max-w-md">
                        <p class="text-sm font-medium">{{ event.label }}</p>
                        <p class="text-muted-foreground mt-0.5 text-xs">
                            {{ event.description }}
                        </p>
                    </div>

                    <!--
                        Mandatory: said in words, not shown as a dead switch.
                    -->
                    <p
                        v-if="event.mandatory"
                        class="text-muted-foreground flex shrink-0 items-center gap-1.5 text-xs"
                    >
                        <Lock class="size-3.5" />
                        Always sent
                    </p>

                    <div v-else class="flex shrink-0 flex-wrap gap-4">
                        <label
                            v-for="channel in event.channels"
                            :key="channel.channel"
                            class="flex items-center gap-2 text-xs"
                            :class="
                                channel.available
                                    ? 'cursor-pointer'
                                    : 'text-muted-foreground cursor-not-allowed'
                            "
                            :title="
                                channel.available
                                    ? undefined
                                    : 'MonaFind does not send this kind of notification on this channel.'
                            "
                        >
                            <Checkbox
                                v-if="channel.available"
                                :model-value="
                                    state[event.event]?.[channel.channel] ??
                                    false
                                "
                                @update:model-value="
                                    (value: boolean | 'indeterminate') => {
                                        state[event.event][channel.channel] =
                                            value === true;
                                    }
                                "
                            />
                            <span
                                v-else
                                class="border-input size-4 rounded border border-dashed"
                                aria-hidden="true"
                            />
                            {{ channel.label }}
                        </label>
                    </div>
                </li>
            </ul>
        </section>

        <Separator />

        <div class="flex items-center gap-3">
            <Button type="submit" :disabled="form.processing">
                Save preferences
            </Button>
            <p
                v-if="form.recentlySuccessful"
                class="text-muted-foreground text-sm"
            >
                Saved.
            </p>
        </div>
    </form>
</template>
