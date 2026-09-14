<script setup lang="ts">
import { Star } from '@lucide/vue';
import { computed } from 'vue';

/**
 * Stars, either as a readout or as a control.
 *
 * One component for both because a review page shows the same five shapes the
 * form collected, and two implementations drift — the display ends up
 * rounding differently from the input and a buyer sees four and a half stars
 * beside the words "you gave 4".
 *
 * As a control it is radio buttons under the surface rather than clickable
 * divs: a keyboard and a screen reader get a labelled group of five options
 * without any extra work, which is what WCAG 2.1 AA asks for here.
 */
const props = withDefaults(
    defineProps<{
        /** The value shown. Fractions are allowed in readout mode. */
        modelValue?: number | null;
        interactive?: boolean;
        size?: 'sm' | 'md' | 'lg';
        label?: string;
    }>(),
    {
        modelValue: null,
        interactive: false,
        size: 'md',
        label: undefined,
    },
);

const emit = defineEmits<{ 'update:modelValue': [value: number] }>();

const stars = [1, 2, 3, 4, 5];

const sizeClass = computed(
    () =>
        ({
            sm: 'size-3.5',
            md: 'size-5',
            lg: 'size-7',
        })[props.size],
);

/** How full star `n` is, 0 to 1 — so 4.4 draws four full and a stub. */
const fillFor = (n: number): number => {
    const value = props.modelValue ?? 0;

    return Math.max(0, Math.min(1, value - (n - 1)));
};

const choose = (n: number): void => emit('update:modelValue', n);
</script>

<template>
    <fieldset v-if="interactive" class="flex items-center gap-1">
        <legend class="sr-only">
            {{ label ?? 'Your rating out of five' }}
        </legend>
        <label
            v-for="n in stars"
            :key="n"
            class="focus-within:ring-ring cursor-pointer rounded-sm p-0.5 focus-within:ring-2"
            :title="`${n} out of 5`"
        >
            <input
                class="sr-only"
                type="radio"
                name="stars"
                :value="n"
                :checked="modelValue === n"
                @change="choose(n)"
            />
            <Star
                :class="[
                    sizeClass,
                    (modelValue ?? 0) >= n
                        ? 'fill-amber-400 text-amber-500'
                        : 'text-muted-foreground',
                ]"
                aria-hidden="true"
            />
            <span class="sr-only">{{ n }} star{{ n === 1 ? '' : 's' }}</span>
        </label>
    </fieldset>

    <div
        v-else
        class="flex items-center gap-0.5"
        role="img"
        :aria-label="
            modelValue === null
                ? 'Not yet rated'
                : `${modelValue} out of 5 stars`
        "
    >
        <span v-for="n in stars" :key="n" class="relative inline-flex">
            <Star
                :class="[sizeClass, 'text-muted-foreground/40']"
                aria-hidden="true"
            />
            <span
                class="absolute inset-0 overflow-hidden"
                :style="{ width: `${fillFor(n) * 100}%` }"
                aria-hidden="true"
            >
                <Star :class="[sizeClass, 'fill-amber-400 text-amber-500']" />
            </span>
        </span>
    </div>
</template>
