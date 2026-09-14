<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';

/**
 * The proof-of-human box on a public form.
 *
 * Drop it into any form whose server-side rules carry `CaptchaRule::for(...)`
 * with the same name, and pass through the form's error for `captcha_token`:
 *
 *     <CaptchaField form="register" :error="errors.captcha_token" />
 *
 * ## It renders nothing when nothing is challenged
 *
 * The site key is shared with every page and is null on a deployment with no
 * captcha configured — local development and the test suite among them. So
 * the component is a no-op there rather than an empty box or a script that
 * never loads, and the same form template serves both.
 *
 * ## Why the widget is reset on an error
 *
 * A Turnstile token is single-use. If the server rejects a submission for any
 * reason — a bad token, or a duplicate email address — the token in the form
 * has already been spent, and submitting again without a fresh one fails a
 * second time. To the person that looks like a form that has stopped working,
 * so any error resets the widget and mints a new token.
 */
const props = withDefaults(
    defineProps<{
        /** The form name, matching `integrations.captcha.forms`. */
        form: string;
        /** The server's error for `captcha_token`, if any. */
        error?: string;
        /** Name of the hidden input the token is written to. */
        name?: string;
    }>(),
    { name: 'captcha_token', error: undefined },
);

interface TurnstileApi {
    render: (
        element: HTMLElement,
        options: {
            sitekey: string;
            callback: (token: string) => void;
            'error-callback': () => void;
            'expired-callback': () => void;
            theme: string;
        },
    ) => string;
    reset: (widgetId: string) => void;
    remove: (widgetId: string) => void;
}

declare global {
    interface Window {
        turnstile?: TurnstileApi;
    }
}

const SCRIPT_SRC =
    'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

const page = usePage();

const siteKey = computed(() => page.props.captcha?.siteKey ?? null);
const active = computed(
    () =>
        siteKey.value !== null &&
        (page.props.captcha?.forms ?? []).includes(props.form),
);

const container = ref<HTMLElement | null>(null);
const token = ref('');
const widgetId = ref<string | null>(null);
const failed = ref(false);

/**
 * Load the Turnstile script once per document, however many widgets a page
 * happens to hold.
 */
function loadScript(): Promise<void> {
    if (window.turnstile) {
        return Promise.resolve();
    }

    const existing = document.querySelector<HTMLScriptElement>(
        `script[src="${SCRIPT_SRC}"]`,
    );

    if (existing) {
        return new Promise((resolve, reject) => {
            existing.addEventListener('load', () => resolve());
            existing.addEventListener('error', () =>
                reject(new Error('turnstile failed to load')),
            );
        });
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = SCRIPT_SRC;
        script.async = true;
        script.defer = true;
        script.addEventListener('load', () => resolve());
        script.addEventListener('error', () =>
            reject(new Error('turnstile failed to load')),
        );
        document.head.append(script);
    });
}

async function render(): Promise<void> {
    if (!active.value || !container.value || siteKey.value === null) {
        return;
    }

    try {
        await loadScript();
    } catch {
        /*
         * Cloudflare is unreachable. The server fails open on exactly this
         * case, so the form still submits — say so rather than leaving a
         * blank space where a challenge should be.
         */
        failed.value = true;

        return;
    }

    if (!window.turnstile) {
        failed.value = true;

        return;
    }

    widgetId.value = window.turnstile.render(container.value, {
        sitekey: siteKey.value,
        callback: (value: string) => {
            token.value = value;
            failed.value = false;
        },
        'error-callback': () => {
            token.value = '';
            failed.value = true;
        },
        'expired-callback': () => {
            token.value = '';
        },
        theme: 'auto',
    });
}

onMounted(render);

onBeforeUnmount(() => {
    if (widgetId.value !== null && window.turnstile) {
        window.turnstile.remove(widgetId.value);
    }
});

/* A spent token cannot be reused; any error means minting a fresh one. */
watch(
    () => props.error,
    (error) => {
        if (error && widgetId.value !== null && window.turnstile) {
            token.value = '';
            window.turnstile.reset(widgetId.value);
        }
    },
);
</script>

<template>
    <div v-if="active" class="grid gap-2">
        <input type="hidden" :name="name" :value="token" />

        <div ref="container" />

        <p v-if="failed" class="text-muted-foreground text-xs">
            The verification check could not load. You can still submit the
            form.
        </p>

        <InputError :message="error" />
    </div>
</template>
