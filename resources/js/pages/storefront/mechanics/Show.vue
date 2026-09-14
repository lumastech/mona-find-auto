<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Briefcase, Car, GraduationCap, MapPin, Star } from '@lucide/vue';
import MechanicBadges from '@/components/mechanics/MechanicBadges.vue';
import RatingList from '@/components/ratings/RatingList.vue';
import RatingSummary from '@/components/ratings/RatingSummary.vue';
import BlurredContact from '@/components/storefront/BlurredContact.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type {
    MechanicProfile,
    Rating,
    RatingSummary as RatingSummaryType,
} from '@/types';

/**
 * One mechanic's public page.
 *
 * Server-rendered so search engines index it. Only approved profiles ever
 * reach here — an unapproved one is a 404, including to its own author, who
 * reads it on the application page instead.
 *
 * The contact block follows the same rule as a seller's: a guest gets labels
 * and a blur over a value the server already replaced with a mask.
 */
defineProps<{
    mechanic: MechanicProfile;
    reviews: {
        summary: RatingSummaryType;
        reviews: {
            data: Rating[];
            links: { url: string | null; label: string; active: boolean }[];
        };
    };
    reportReasons?: { value: string; label: string }[];
}>();
</script>

<template>
    <Head :title="mechanic.display_name">
        <meta
            name="description"
            :content="
                mechanic.headline ??
                `${mechanic.qualification} in ${mechanic.location.city}. Approved on MonaFindAuto.`
            "
        />
    </Head>

    <article class="space-y-8">
        <header class="flex flex-wrap items-start gap-4">
            <img
                v-if="mechanic.avatar_url"
                :src="mechanic.avatar_url"
                :alt="mechanic.display_name"
                class="size-20 shrink-0 rounded-full border object-cover"
            />
            <div
                v-else
                class="bg-muted text-muted-foreground flex size-20 shrink-0 items-center justify-center rounded-full border text-2xl font-semibold"
                aria-hidden="true"
            >
                {{ mechanic.display_name.charAt(0) }}
            </div>

            <div class="min-w-0 flex-1 space-y-2">
                <h1 class="text-2xl font-semibold">
                    {{ mechanic.display_name }}
                </h1>
                <p v-if="mechanic.headline" class="text-muted-foreground">
                    {{ mechanic.headline }}
                </p>

                <MechanicBadges
                    :approved="mechanic.approved"
                    :endorsements="mechanic.endorsements"
                />

                <div
                    class="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1 text-sm"
                >
                    <span class="flex items-center gap-1.5">
                        <MapPin class="size-4" aria-hidden="true" />
                        {{ mechanic.location.locality }}
                    </span>
                    <span class="flex items-center gap-1.5">
                        <Car class="size-4" aria-hidden="true" />
                        {{ mechanic.years_experience }} years’ experience
                    </span>
                    <span class="flex items-center gap-1.5">
                        <Star class="size-4" aria-hidden="true" />
                        <template v-if="mechanic.rating.average !== null">
                            {{ mechanic.rating.average.toFixed(1) }} out of 5
                            ({{ mechanic.rating.count }})
                        </template>
                        <template v-else>No reviews yet</template>
                    </span>
                </div>

                <div class="flex flex-wrap gap-1.5">
                    <Badge v-if="mechanic.is_mobile" variant="outline">
                        Comes to your vehicle
                    </Badge>
                    <Badge
                        :variant="
                            mechanic.accepting_work ? 'outline' : 'secondary'
                        "
                    >
                        {{
                            mechanic.accepting_work
                                ? 'Taking work now'
                                : 'Not taking work at the moment'
                        }}
                    </Badge>
                </div>
            </div>
        </header>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <Card v-if="mechanic.bio">
                    <CardHeader><CardTitle>About</CardTitle></CardHeader>
                    <CardContent>
                        <p class="text-sm whitespace-pre-line">
                            {{ mechanic.bio }}
                        </p>
                    </CardContent>
                </Card>

                <Card v-if="mechanic.specialities.length">
                    <CardHeader><CardTitle>Specialities</CardTitle></CardHeader>
                    <CardContent class="flex flex-wrap gap-1.5">
                        <Badge
                            v-for="speciality in mechanic.specialities"
                            :key="speciality.id"
                            variant="secondary"
                            class="font-normal"
                        >
                            {{ speciality.name }}
                        </Badge>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <GraduationCap class="size-4" aria-hidden="true" />
                            Qualification
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-1 text-sm">
                        <p class="font-medium">{{ mechanic.qualification }}</p>
                        <p
                            v-if="mechanic.qualification_institution"
                            class="text-muted-foreground"
                        >
                            {{ mechanic.qualification_institution
                            }}<template v-if="mechanic.qualification_year">
                                · {{ mechanic.qualification_year }}</template
                            >
                        </p>
                        <p class="text-muted-foreground pt-2 text-xs">
                            Checked by MonaFind before this profile was
                            published.
                        </p>
                    </CardContent>
                </Card>

                <Card v-if="mechanic.work_history.length">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <Briefcase class="size-4" aria-hidden="true" />
                            Work history
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ol class="space-y-4">
                            <li
                                v-for="(job, index) in mechanic.work_history"
                                :key="index"
                                class="border-s ps-4"
                            >
                                <p class="font-medium">{{ job.role }}</p>
                                <p class="text-muted-foreground text-sm">
                                    {{ job.employer
                                    }}<template v-if="job.period">
                                        · {{ job.period }}</template
                                    >
                                </p>
                                <p v-if="job.description" class="mt-1 text-sm">
                                    {{ job.description }}
                                </p>
                            </li>
                        </ol>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Reviews</CardTitle></CardHeader>
                    <CardContent class="space-y-6">
                        <RatingSummary
                            :summary="reviews.summary"
                            heading="Reviews"
                        />
                        <RatingList
                            :reviews="reviews.reviews"
                            :report-reasons="reportReasons ?? []"
                        />
                    </CardContent>
                </Card>
            </div>

            <div class="space-y-6">
                <Card>
                    <CardHeader><CardTitle>Get in touch</CardTitle></CardHeader>
                    <CardContent>
                        <BlurredContact
                            :contact="mechanic.contact"
                            :business-name="mechanic.display_name"
                        />
                    </CardContent>
                </Card>

                <Card v-if="mechanic.endorsements.length">
                    <CardHeader>
                        <CardTitle>Endorsed by</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul class="space-y-2 text-sm">
                            <li
                                v-for="endorsement in mechanic.endorsements"
                                :key="endorsement.id"
                            >
                                <Link
                                    :href="`/sellers/${endorsement.seller.slug}`"
                                    class="font-medium hover:underline"
                                >
                                    {{ endorsement.seller.business_name }}
                                </Link>
                                <span
                                    v-if="endorsement.seller.verified"
                                    class="text-muted-foreground"
                                >
                                    · verified seller
                                </span>
                            </li>
                        </ul>
                        <p class="text-muted-foreground mt-3 text-xs">
                            A shop’s endorsement is its own word, given
                            separately from MonaFind’s approval. Either can be
                            withdrawn.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </div>
    </article>
</template>
