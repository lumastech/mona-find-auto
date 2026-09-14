<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    MechanicApplicationForm,
    MechanicApplicationStatus,
    MechanicEndorsement,
    MechanicSpeciality,
    ProvinceWithCities,
} from '@/types';

/**
 * Applying to be a listed mechanic, and watching that application.
 *
 * One form rather than a wizard: a mechanic profile is one thing — who you
 * are, where you are, what you do, where you have worked — and staging it
 * would add round trips without saving any state worth keeping.
 *
 * It stops being editable the moment it is sent, because a reviewer has to
 * read what was submitted. The page stays, showing where the application
 * stands and the endorsements the mechanic has collected.
 */
const props = defineProps<{
    profile: MechanicApplicationForm;
    status: MechanicApplicationStatus;
    specialities: MechanicSpeciality[];
    provinces: ProvinceWithCities[];
    endorsements: MechanicEndorsement[];
    canRequestEndorsements: boolean;
}>();

/**
 * Nulls are coerced to empty strings for the inputs' sake. Laravel turns an
 * empty string back into null before validation, so a field left blank is
 * still null in the database rather than an empty string masquerading as an
 * answer.
 */
const form = useForm({
    ...props.profile,
    headline: props.profile.headline ?? '',
    bio: props.profile.bio ?? '',
    qualification_institution: props.profile.qualification_institution ?? '',
    qualification_year: props.profile.qualification_year ?? '',
    street: props.profile.street ?? '',
    plot_number: props.profile.plot_number ?? '',
    email: props.profile.email ?? '',
    work_history: props.profile.work_history.map((job) => ({
        ...job,
        description: job.description ?? '',
        started_on: job.started_on ?? '',
        ended_on: job.ended_on ?? '',
    })),
    references: props.profile.references.map((reference) => ({
        ...reference,
        relationship: reference.relationship ?? '',
        email: reference.email ?? '',
        note: reference.note ?? '',
    })),
});

/**
 * Errors the server raised against the profile as a whole rather than
 * against one field — "you cannot edit this now". They are read off the page
 * props because a form's error type only knows about its own fields.
 */
const page = usePage();
const profileError = computed(
    () => (page.props.errors as Record<string, string | undefined>).profile,
);

const cities = computed(
    () =>
        props.provinces.find(
            (province) => province.id === Number(form.province_id),
        )?.cities ?? [],
);

const toggleSpeciality = (id: number) => {
    form.speciality_ids = form.speciality_ids.includes(id)
        ? form.speciality_ids.filter((value) => value !== id)
        : [...form.speciality_ids, id];
};

const addJob = () =>
    form.work_history.push({
        employer: '',
        role: '',
        description: '',
        started_on: '',
        ended_on: '',
        is_current: false,
    });

const addReference = () =>
    form.references.push({
        name: '',
        relationship: '',
        phone: '',
        email: '',
        note: '',
    });

const save = () => form.post('/mechanics/apply', { preserveScroll: true });

const submitForm = useForm({});
const submitApplication = () =>
    submitForm.post('/mechanics/apply/submit', { preserveScroll: true });

const endorsementForm = useForm({ seller_id: '', message: '' });
const askShop = () =>
    endorsementForm.post('/mechanics/endorsements', {
        preserveScroll: true,
        onSuccess: () => endorsementForm.reset(),
    });
</script>

<template>
    <Head title="Mechanic profile" />

    <div class="mx-auto max-w-3xl space-y-6">
        <header class="space-y-2">
            <h1 class="text-2xl font-semibold">Your mechanic profile</h1>
            <p class="text-muted-foreground text-sm">
                Listed mechanics are found by buyers looking for the work they
                do. MonaFind checks your qualification and work history before
                your profile goes live.
            </p>
        </header>

        <Card>
            <CardHeader class="flex-row items-center justify-between gap-3">
                <CardTitle>Status</CardTitle>
                <Badge :variant="status.variant as never">{{
                    status.label
                }}</Badge>
            </CardHeader>
            <CardContent class="space-y-3 text-sm">
                <p>{{ status.guidance }}</p>

                <p
                    v-if="status.rejection_reason"
                    class="border-destructive/40 bg-destructive/5 rounded-md border p-3"
                >
                    <span class="font-medium">Reason: </span>
                    {{ status.rejection_reason }}
                </p>

                <p v-if="status.public_url">
                    <Link
                        :href="status.public_url"
                        class="font-medium hover:underline"
                    >
                        View your public profile
                    </Link>
                </p>

                <Button
                    v-if="status.editable && profile.exists"
                    :disabled="submitForm.processing"
                    @click="submitApplication"
                >
                    Send for approval
                </Button>
                <InputError :message="profileError" />
            </CardContent>
        </Card>

        <form class="space-y-6" @submit.prevent="save">
            <fieldset :disabled="!status.editable" class="space-y-6">
                <Card>
                    <CardHeader><CardTitle>About you</CardTitle></CardHeader>
                    <CardContent class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1.5 sm:col-span-2">
                            <Label for="display_name">Name buyers see</Label>
                            <Input
                                id="display_name"
                                v-model="form.display_name"
                            />
                            <InputError :message="form.errors.display_name" />
                        </div>

                        <div class="space-y-1.5 sm:col-span-2">
                            <Label for="headline">One-line summary</Label>
                            <Input
                                id="headline"
                                v-model="form.headline"
                                placeholder="Gearbox and clutch specialist"
                            />
                            <InputError :message="form.errors.headline" />
                        </div>

                        <div class="space-y-1.5 sm:col-span-2">
                            <Label for="bio">About your work</Label>
                            <textarea
                                id="bio"
                                v-model="form.bio"
                                rows="4"
                                class="border-input bg-background w-full rounded-md border p-3 text-sm"
                            />
                            <InputError :message="form.errors.bio" />
                        </div>

                        <div class="space-y-1.5">
                            <Label for="phone">Phone</Label>
                            <Input id="phone" v-model="form.phone" />
                            <InputError :message="form.errors.phone" />
                        </div>

                        <div class="space-y-1.5">
                            <Label for="email">Email</Label>
                            <Input
                                id="email"
                                v-model="form.email"
                                type="email"
                            />
                            <InputError :message="form.errors.email" />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        ><CardTitle>Qualification and experience</CardTitle>
                    </CardHeader>
                    <CardContent class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1.5 sm:col-span-2">
                            <Label for="qualification">Qualification</Label>
                            <Input
                                id="qualification"
                                v-model="form.qualification"
                            />
                            <InputError :message="form.errors.qualification" />
                        </div>

                        <div class="space-y-1.5">
                            <Label for="institution">Where you trained</Label>
                            <Input
                                id="institution"
                                v-model="form.qualification_institution"
                            />
                        </div>

                        <div class="space-y-1.5">
                            <Label for="qualification_year">Year</Label>
                            <Input
                                id="qualification_year"
                                v-model="form.qualification_year"
                                type="number"
                            />
                            <InputError
                                :message="form.errors.qualification_year"
                            />
                        </div>

                        <div class="space-y-1.5">
                            <Label for="years_experience"
                                >Years of experience</Label
                            >
                            <Input
                                id="years_experience"
                                v-model="form.years_experience"
                                type="number"
                                min="0"
                            />
                            <InputError
                                :message="form.errors.years_experience"
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Specialities</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p class="text-muted-foreground text-sm">
                            Choose everything you do. This is how buyers filter
                            the directory, so leaving one out means not being
                            found for it.
                        </p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label
                                v-for="speciality in specialities"
                                :key="speciality.id"
                                class="hover:bg-muted/50 flex items-start gap-2 rounded-md border p-2.5"
                            >
                                <Checkbox
                                    :model-value="
                                        form.speciality_ids.includes(
                                            speciality.id,
                                        )
                                    "
                                    @update:model-value="
                                        toggleSpeciality(speciality.id)
                                    "
                                />
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium">{{
                                        speciality.name
                                    }}</span>
                                    <span
                                        v-if="speciality.description"
                                        class="text-muted-foreground block text-xs"
                                        >{{ speciality.description }}</span
                                    >
                                </span>
                            </label>
                        </div>
                        <InputError :message="form.errors.speciality_ids" />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        ><CardTitle>Where you work</CardTitle></CardHeader
                    >
                    <CardContent class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="province_id">Province</Label>
                            <select
                                id="province_id"
                                v-model="form.province_id"
                                class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            >
                                <option :value="null">Choose a province</option>
                                <option
                                    v-for="province in provinces"
                                    :key="province.id"
                                    :value="province.id"
                                >
                                    {{ province.name }}
                                </option>
                            </select>
                            <InputError :message="form.errors.province_id" />
                        </div>

                        <div class="space-y-1.5">
                            <Label for="city_id">Town</Label>
                            <select
                                id="city_id"
                                v-model="form.city_id"
                                class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            >
                                <option :value="null">Choose a town</option>
                                <option
                                    v-for="city in cities"
                                    :key="city.id"
                                    :value="city.id"
                                >
                                    {{ city.name }}
                                </option>
                            </select>
                            <InputError :message="form.errors.city_id" />
                        </div>

                        <div class="space-y-1.5">
                            <Label for="street">Street</Label>
                            <Input id="street" v-model="form.street" />
                        </div>

                        <div class="space-y-1.5">
                            <Label for="plot_number">Plot number</Label>
                            <Input
                                id="plot_number"
                                v-model="form.plot_number"
                            />
                        </div>

                        <div class="flex items-center gap-2 sm:col-span-2">
                            <Checkbox id="is_mobile" v-model="form.is_mobile" />
                            <Label for="is_mobile" class="font-normal">
                                I travel to the vehicle
                            </Label>
                        </div>

                        <div class="flex items-center gap-2 sm:col-span-2">
                            <Checkbox
                                id="accepting_work"
                                v-model="form.accepting_work"
                            />
                            <Label for="accepting_work" class="font-normal">
                                I am taking work at the moment
                            </Label>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex-row items-center justify-between gap-3"
                    >
                        <CardTitle>Work history</CardTitle>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            class="gap-1.5"
                            @click="addJob"
                        >
                            <Plus class="size-4" aria-hidden="true" /> Add a job
                        </Button>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <p
                            v-if="!form.work_history.length"
                            class="text-muted-foreground text-sm"
                        >
                            Where have you worked? A reviewer checks these.
                        </p>

                        <div
                            v-for="(job, index) in form.work_history"
                            :key="index"
                            class="grid gap-3 rounded-md border p-3 sm:grid-cols-2"
                        >
                            <div class="space-y-1.5">
                                <Label :for="`employer-${index}`"
                                    >Employer</Label
                                >
                                <Input
                                    :id="`employer-${index}`"
                                    v-model="job.employer"
                                />
                                <InputError
                                    :message="
                                        form.errors[
                                            `work_history.${index}.employer` as never
                                        ]
                                    "
                                />
                            </div>
                            <div class="space-y-1.5">
                                <Label :for="`role-${index}`">Role</Label>
                                <Input
                                    :id="`role-${index}`"
                                    v-model="job.role"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <Label :for="`started-${index}`">From</Label>
                                <Input
                                    :id="`started-${index}`"
                                    v-model="job.started_on"
                                    type="date"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <Label :for="`ended-${index}`">To</Label>
                                <Input
                                    :id="`ended-${index}`"
                                    v-model="job.ended_on"
                                    type="date"
                                    :disabled="job.is_current"
                                />
                            </div>
                            <div class="flex items-center gap-2">
                                <Checkbox
                                    :id="`current-${index}`"
                                    v-model="job.is_current"
                                />
                                <Label
                                    :for="`current-${index}`"
                                    class="font-normal"
                                    >Still there</Label
                                >
                            </div>
                            <div class="flex items-end justify-end">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    @click="form.work_history.splice(index, 1)"
                                >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                    Remove
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex-row items-center justify-between gap-3"
                    >
                        <CardTitle>References (optional)</CardTitle>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            class="gap-1.5"
                            @click="addReference"
                        >
                            <Plus class="size-4" aria-hidden="true" /> Add
                        </Button>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <p class="text-muted-foreground text-sm">
                            Somebody MonaFind may ring about your work. These
                            are never shown on your public profile.
                        </p>

                        <div
                            v-for="(reference, index) in form.references"
                            :key="index"
                            class="grid gap-3 rounded-md border p-3 sm:grid-cols-2"
                        >
                            <div class="space-y-1.5">
                                <Label :for="`ref-name-${index}`">Name</Label>
                                <Input
                                    :id="`ref-name-${index}`"
                                    v-model="reference.name"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <Label :for="`ref-phone-${index}`">Phone</Label>
                                <Input
                                    :id="`ref-phone-${index}`"
                                    v-model="reference.phone"
                                />
                                <InputError
                                    :message="
                                        form.errors[
                                            `references.${index}.phone` as never
                                        ]
                                    "
                                />
                            </div>
                            <div class="space-y-1.5 sm:col-span-2">
                                <Label :for="`ref-rel-${index}`"
                                    >How they know you</Label
                                >
                                <Input
                                    :id="`ref-rel-${index}`"
                                    v-model="reference.relationship"
                                />
                            </div>
                            <div class="flex justify-end sm:col-span-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    @click="form.references.splice(index, 1)"
                                >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                    Remove
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing">
                        Save profile
                    </Button>
                    <InputError :message="profileError" />
                </div>
            </fieldset>
        </form>

        <Card>
            <CardHeader><CardTitle>Endorsements</CardTitle></CardHeader>
            <CardContent class="space-y-4">
                <p class="text-muted-foreground text-sm">
                    A shop you work with can vouch for you. Their name shows as
                    a badge on your profile, alongside MonaFind’s approval, and
                    they can withdraw it at any time.
                </p>

                <ul v-if="endorsements.length" class="space-y-2">
                    <li
                        v-for="endorsement in endorsements"
                        :key="endorsement.id"
                        class="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3 text-sm"
                    >
                        <span class="font-medium">{{
                            endorsement.seller.business_name
                        }}</span>
                        <span class="flex items-center gap-2">
                            <span
                                v-if="endorsement.response_note"
                                class="text-muted-foreground"
                            >
                                “{{ endorsement.response_note }}”
                            </span>
                            <Badge
                                :variant="endorsement.status_variant as never"
                            >
                                {{ endorsement.status_label }}
                            </Badge>
                        </span>
                    </li>
                </ul>

                <form
                    v-if="canRequestEndorsements"
                    class="space-y-3 border-t pt-4"
                    @submit.prevent="askShop"
                >
                    <div class="space-y-1.5">
                        <Label for="seller_id">Shop ID</Label>
                        <Input
                            id="seller_id"
                            v-model="endorsementForm.seller_id"
                            placeholder="The shop's MonaFind ID"
                        />
                        <InputError
                            :message="endorsementForm.errors.seller_id"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="message">Message (optional)</Label>
                        <textarea
                            id="message"
                            v-model="endorsementForm.message"
                            rows="3"
                            class="border-input bg-background w-full rounded-md border p-3 text-sm"
                        />
                    </div>
                    <Button
                        type="submit"
                        :disabled="endorsementForm.processing"
                    >
                        Ask for an endorsement
                    </Button>
                </form>

                <p v-else class="text-muted-foreground text-sm">
                    You can ask shops for endorsements once MonaFind has
                    approved your profile.
                </p>
            </CardContent>
        </Card>
    </div>
</template>
