<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Car, MapPin, Star } from '@lucide/vue';
import MechanicBadges from '@/components/mechanics/MechanicBadges.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import type { MechanicProfile } from '@/types';

/**
 * One mechanic in the directory.
 *
 * The rating shows "No reviews yet" rather than a zero when nobody has
 * reviewed them: a new mechanic with no history is not a bad mechanic, and a
 * greyed-out nought reads like one.
 */
defineProps<{ mechanic: MechanicProfile }>();
</script>

<template>
    <Card class="h-full transition-shadow hover:shadow-md">
        <CardContent class="flex h-full flex-col gap-3 p-4">
            <div class="flex items-start gap-3">
                <img
                    v-if="mechanic.avatar_url"
                    :src="mechanic.avatar_url"
                    :alt="`${mechanic.display_name}`"
                    class="size-14 shrink-0 rounded-full border object-cover"
                    loading="lazy"
                />
                <div
                    v-else
                    class="bg-muted text-muted-foreground flex size-14 shrink-0 items-center justify-center rounded-full border text-lg font-semibold"
                    aria-hidden="true"
                >
                    {{ mechanic.display_name.charAt(0) }}
                </div>

                <div class="min-w-0 flex-1">
                    <h3 class="truncate font-semibold">
                        <Link
                            :href="`/mechanics/${mechanic.slug}`"
                            class="hover:underline"
                        >
                            {{ mechanic.display_name }}
                        </Link>
                    </h3>
                    <p
                        v-if="mechanic.headline"
                        class="text-muted-foreground line-clamp-2 text-sm"
                    >
                        {{ mechanic.headline }}
                    </p>
                </div>
            </div>

            <MechanicBadges
                :approved="mechanic.approved"
                :endorsements="mechanic.endorsements"
                :limit="2"
            />

            <ul class="text-muted-foreground space-y-1 text-sm">
                <li class="flex items-center gap-1.5">
                    <MapPin class="size-3.5 shrink-0" aria-hidden="true" />
                    <span class="truncate">{{
                        mechanic.location.locality
                    }}</span>
                </li>
                <li class="flex items-center gap-1.5">
                    <Car class="size-3.5 shrink-0" aria-hidden="true" />
                    <span class="truncate">
                        {{ mechanic.years_experience }} years’ experience
                        <template v-if="mechanic.is_mobile">
                            · comes to you</template
                        >
                    </span>
                </li>
                <li class="flex items-center gap-1.5">
                    <Star class="size-3.5 shrink-0" aria-hidden="true" />
                    <span v-if="mechanic.rating.average !== null">
                        {{ mechanic.rating.average.toFixed(1) }} out of 5 ·
                        {{ mechanic.rating.count }}
                        {{ mechanic.rating.count === 1 ? 'review' : 'reviews' }}
                    </span>
                    <span v-else>No reviews yet</span>
                </li>
            </ul>

            <div
                v-if="mechanic.specialities.length"
                class="mt-auto flex flex-wrap gap-1"
            >
                <Badge
                    v-for="speciality in mechanic.specialities.slice(0, 3)"
                    :key="speciality.id"
                    variant="outline"
                    class="font-normal"
                >
                    {{ speciality.name }}
                </Badge>
                <Badge
                    v-if="mechanic.specialities.length > 3"
                    variant="outline"
                    class="font-normal"
                >
                    +{{ mechanic.specialities.length - 3 }}
                </Badge>
            </div>

            <Badge
                v-if="!mechanic.accepting_work"
                variant="secondary"
                class="w-fit"
            >
                Not taking work at the moment
            </Badge>
        </CardContent>
    </Card>
</template>
