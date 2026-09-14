<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import endorsements from '@/routes/seller/endorsements';
import mechanics from '@/routes/mechanics';
import type { MechanicEndorsement, MechanicEndorsementPage } from '@/types';

/**
 * The shop's endorsement queue.
 *
 * Endorsing puts this business's name on somebody else's public profile, so
 * the page says so rather than making it a one-tap action whose consequence
 * is discovered later. Withdrawing needs a reason, which the mechanic reads.
 */
defineProps<{
    pending: MechanicEndorsement[];
    decided: MechanicEndorsementPage;
}>();

/** Which answered endorsement has its withdrawal form open. */
const openRevoke = ref<number | null>(null);
</script>

<template>
    <Head title="Endorsements" />

    <div class="space-y-6">
        <Heading
            title="Endorsements"
            description="Mechanics who have asked your shop to vouch for them. Your business name appears on the profile of anyone you endorse."
        />

        <Card>
            <CardHeader>
                <CardTitle>
                    Waiting for your answer ({{ pending.length }})
                </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
                <p v-if="!pending.length" class="text-muted-foreground text-sm">
                    Nothing waiting.
                </p>

                <article
                    v-for="endorsement in pending"
                    :key="endorsement.id"
                    class="space-y-3 rounded-lg border p-4"
                >
                    <header class="flex flex-wrap items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <h3 class="font-semibold">
                                <Link
                                    :href="
                                        mechanics.show(
                                            endorsement.mechanic.slug,
                                        )
                                    "
                                    class="hover:underline"
                                >
                                    {{ endorsement.mechanic.display_name }}
                                </Link>
                            </h3>
                            <p class="text-muted-foreground text-sm">
                                {{ endorsement.mechanic.qualification }} ·
                                {{ endorsement.mechanic.years_experience }}
                                years · {{ endorsement.mechanic.locality }}
                            </p>
                        </div>
                        <Badge
                            v-if="endorsement.mechanic.approved"
                            variant="default"
                        >
                            MonaFind approved
                        </Badge>
                    </header>

                    <p
                        v-if="endorsement.message"
                        class="bg-muted/50 rounded-md p-3 text-sm"
                    >
                        “{{ endorsement.message }}”
                    </p>

                    <Form
                        v-bind="endorsements.endorse.form(endorsement.id)"
                        v-slot="{ errors, processing }"
                        class="space-y-3"
                    >
                        <div class="space-y-1.5">
                            <Label :for="`note-${endorsement.id}`">
                                A note back (optional)
                            </Label>
                            <textarea
                                :id="`note-${endorsement.id}`"
                                name="note"
                                rows="2"
                                class="border-input bg-background w-full rounded-md border p-2.5 text-sm"
                            />
                            <InputError :message="errors.note" />
                            <InputError :message="errors.endorsement" />
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <Button type="submit" :disabled="processing">
                                <Spinner v-if="processing" class="size-4" />
                                Endorse
                            </Button>
                            <Button
                                type="submit"
                                variant="outline"
                                :disabled="processing"
                                :formaction="
                                    endorsements.decline.url(endorsement.id)
                                "
                            >
                                Decline
                            </Button>
                        </div>
                    </Form>
                </article>
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Answered</CardTitle></CardHeader>
            <CardContent class="space-y-3">
                <p
                    v-if="!decided.data.length"
                    class="text-muted-foreground text-sm"
                >
                    You have not answered any requests yet.
                </p>

                <article
                    v-for="endorsement in decided.data"
                    :key="endorsement.id"
                    class="space-y-2 rounded-lg border p-4"
                >
                    <header class="flex flex-wrap items-center gap-3">
                        <Link
                            :href="mechanics.show(endorsement.mechanic.slug)"
                            class="font-medium hover:underline"
                        >
                            {{ endorsement.mechanic.display_name }}
                        </Link>
                        <Badge :variant="endorsement.status_variant as never">
                            {{ endorsement.status_label }}
                        </Badge>

                        <Button
                            v-if="endorsement.can.revoke"
                            size="sm"
                            variant="ghost"
                            class="ms-auto"
                            @click="
                                openRevoke =
                                    openRevoke === endorsement.id
                                        ? null
                                        : endorsement.id
                            "
                        >
                            Withdraw
                        </Button>
                    </header>

                    <p
                        v-if="endorsement.revocation_reason"
                        class="text-muted-foreground text-sm"
                    >
                        Withdrawn: {{ endorsement.revocation_reason }}
                    </p>

                    <Form
                        v-if="openRevoke === endorsement.id"
                        v-bind="endorsements.revoke.form(endorsement.id)"
                        v-slot="{ errors, processing }"
                        class="space-y-2 border-t pt-3"
                        @success="openRevoke = null"
                    >
                        <Label :for="`reason-${endorsement.id}`">
                            Why are you withdrawing?
                        </Label>
                        <textarea
                            :id="`reason-${endorsement.id}`"
                            name="reason"
                            rows="2"
                            required
                            class="border-input bg-background w-full rounded-md border p-2.5 text-sm"
                        />
                        <InputError :message="errors.reason" />
                        <InputError :message="errors.endorsement" />
                        <p class="text-muted-foreground text-xs">
                            The mechanic is told, and the badge comes off their
                            profile straight away.
                        </p>
                        <Button
                            type="submit"
                            variant="destructive"
                            size="sm"
                            :disabled="processing"
                        >
                            <Spinner v-if="processing" class="size-4" />
                            Withdraw endorsement
                        </Button>
                    </Form>
                </article>
            </CardContent>
        </Card>
    </div>
</template>
