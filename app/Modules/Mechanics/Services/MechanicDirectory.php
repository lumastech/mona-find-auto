<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Services;

use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Models\MechanicSpeciality;
use App\Modules\Mechanics\Support\DirectoryFilters;
use App\Modules\Ratings\Enums\RatingDirection;
use App\Modules\Ratings\Enums\RatingStatus;
use App\Modules\Ratings\Models\Rating;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The public mechanic directory: who is listed, filtered how.
 *
 * The storefront page and /api/v1/mechanics both go through here, so a filter
 * cannot mean one thing on the web and another in the app — and, more
 * importantly, so the "approved only" rule is applied once. The query starts
 * from publiclyVisible() and nothing a caller passes can widen it.
 *
 * Ratings are a subquery rather than a join because a mechanic with no
 * reviews must still appear: a join to `ratings` would quietly drop everybody
 * who has not been reviewed yet, which is most of the directory on the day it
 * opens. The subquery counts only public directions and only published rows,
 * matching what the profile page's feed will show.
 */
class MechanicDirectory
{
    /** How many profiles a directory page holds. */
    public const PER_PAGE = 12;

    /**
     * A page of the directory.
     *
     * @return LengthAwarePaginator<int, MechanicProfile>
     */
    public function search(DirectoryFilters $filters, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $this->baseQuery($filters)
            ->orderByDesc('rating_average')
            ->orderByDesc('rating_count')
            ->orderByDesc('years_experience')
            ->orderBy('display_name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The speciality list the filter bar draws, ordered as staff arranged it.
     *
     * @return array<int, array{id: int, name: string, slug: string, description: string|null}>
     */
    public function specialityOptions(): array
    {
        return MechanicSpeciality::query()
            ->active()
            ->ordered()
            ->get()
            ->map(static fn (MechanicSpeciality $speciality): array => $speciality->toOption())
            ->all();
    }

    /**
     * Attach the rating columns to a single profile, so the profile page's
     * header shows the same numbers the directory card did.
     */
    public function withRatings(MechanicProfile $profile): MechanicProfile
    {
        return MechanicProfile::query()
            ->whereKey($profile->getKey())
            ->select('mechanic_profiles.*')
            ->addSelect([
                'rating_average' => $this->ratingSubquery('avg'),
                'rating_count' => $this->ratingSubquery('count'),
            ])
            ->firstOrFail();
    }

    /**
     * The filtered query, before ordering.
     *
     * @return Builder<MechanicProfile>
     */
    private function baseQuery(DirectoryFilters $filters): Builder
    {
        return MechanicProfile::query()
            /* First and unconditionally: an unapproved profile is not a profile. */
            ->publiclyVisible()
            ->select('mechanic_profiles.*')
            ->addSelect([
                'rating_average' => $this->ratingSubquery('avg'),
                'rating_count' => $this->ratingSubquery('count'),
            ])
            ->with(['province', 'city', 'specialities', 'activeEndorsements.seller', 'media'])
            ->search($filters->search)
            ->when(
                $filters->specialityId,
                fn (Builder $query, int $id) => $query->whereHas(
                    'specialities',
                    static fn (Builder $specialities): Builder => $specialities->whereKey($id),
                ),
            )
            ->when($filters->provinceId, fn (Builder $query, int $id) => $query->where('province_id', $id))
            ->when($filters->cityId, fn (Builder $query, int $id) => $query->where('city_id', $id))
            ->when($filters->acceptingWork, fn (Builder $query) => $query->where('accepting_work', true))
            /*
             * "Endorsed only" is a filter on live endorsements, so a badge a
             * shop has withdrawn stops matching the moment it is revoked.
             */
            ->when($filters->endorsedOnly, fn (Builder $query) => $query->whereHas('activeEndorsements'))
            ->when(
                $filters->minimumRating,
                fn (Builder $query, float $minimum) => $query->whereIn('mechanic_profiles.id', $this->idsRatedAtLeast($minimum)),
            );
    }

    /**
     * The profiles whose public average is at or above a threshold.
     *
     * A grouped subquery rather than a HAVING over the `rating_average`
     * select alias: an alias is not addressable in HAVING on every engine —
     * SQLite rejects the whole statement as "a HAVING clause on a
     * non-aggregate query" — and a filter that works on MySQL and 500s in
     * the test suite is worse than no filter.
     *
     * A mechanic nobody has reviewed has no average and so does not clear a
     * threshold. That is deliberate, and it is not the same as excluding
     * them from the directory: they appear in every unfiltered list.
     *
     * The comparison is done in hundredths against an INTEGER binding rather
     * than against the float itself. PDO binds a PHP float as a string, and
     * SQLite orders every REAL below every TEXT value, so `avg(stars) >= ?`
     * bound with 4.0 matches nothing at all — silently, and only on the
     * engine the tests run against.
     *
     * @return Builder<Rating>
     */
    private function idsRatedAtLeast(float $minimum): Builder
    {
        return Rating::query()
            ->select('ratee_id')
            ->where('ratee_type', (new MechanicProfile)->getMorphClass())
            ->whereIn('direction', RatingDirection::publicValues())
            ->where('status', RatingStatus::Published)
            ->groupBy('ratee_id')
            ->havingRaw('avg(stars) * 100 >= ?', [(int) round($minimum * 100)]);
    }

    /**
     * The average or count of public, published ratings about a profile.
     *
     * @return Builder<Rating>
     */
    private function ratingSubquery(string $aggregate): Builder
    {
        return Rating::query()
            ->selectRaw($aggregate === 'avg' ? 'avg(stars)' : 'count(*)')
            ->whereColumn('ratee_id', 'mechanic_profiles.id')
            ->where('ratee_type', (new MechanicProfile)->getMorphClass())
            ->whereIn('direction', RatingDirection::publicValues())
            ->where('status', RatingStatus::Published);
    }
}
