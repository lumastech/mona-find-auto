<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import sellers from '@/routes/sellers';
import type { RegistrationStepOption, RegistrationStepValue } from '@/types';

/**
 * Where the applicant is in the wizard, and where they may go back to.
 *
 * Finished steps are links because people do go back — they mistype a phone
 * number and notice three screens later. Steps they have not reached yet are
 * not, because the server refuses them anyway.
 */
const props = defineProps<{
    steps: RegistrationStepOption[];
    current: RegistrationStepValue;
    furthest: RegistrationStepValue;
}>();

const positionOf = (step: RegistrationStepValue): number =>
    props.steps.find((option) => option.value === step)?.position ?? 1;

const currentPosition = computed(() => positionOf(props.current));
const reachablePosition = computed(() => positionOf(props.furthest) + 1);
</script>

<template>
    <nav aria-label="Sign-up progress">
        <ol class="flex flex-wrap gap-x-2 gap-y-3">
            <li
                v-for="step in steps"
                :key="step.value"
                class="flex min-w-0 items-center gap-2"
            >
                <component
                    :is="step.position <= reachablePosition ? Link : 'span'"
                    :href="
                        step.position <= reachablePosition
                            ? sellers.register.step({ step: step.value })
                            : undefined
                    "
                    class="flex items-center gap-2 rounded-full py-1 pr-3 pl-1 text-sm"
                    :class="
                        step.position === currentPosition
                            ? 'bg-primary/10 text-foreground font-medium'
                            : 'text-muted-foreground'
                    "
                    :aria-current="
                        step.position === currentPosition ? 'step' : undefined
                    "
                >
                    <span
                        class="flex size-6 shrink-0 items-center justify-center rounded-full border text-xs"
                        :class="
                            step.position < currentPosition
                                ? 'bg-primary text-primary-foreground border-transparent'
                                : step.position === currentPosition
                                  ? 'border-primary text-primary'
                                  : ''
                        "
                    >
                        <Check
                            v-if="step.position < currentPosition"
                            class="size-3.5"
                            aria-hidden="true"
                        />
                        <template v-else>{{ step.position }}</template>
                    </span>
                    <span class="truncate">{{ step.label }}</span>
                </component>
            </li>
        </ol>
    </nav>
</template>
