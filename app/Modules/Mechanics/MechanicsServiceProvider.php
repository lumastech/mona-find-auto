<?php

declare(strict_types=1);

namespace App\Modules\Mechanics;

use App\Modules\Mechanics\Models\MechanicEndorsement;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Models\MechanicSpeciality;
use App\Modules\Mechanics\Policies\EndorsementPolicy;
use App\Modules\Mechanics\Policies\MechanicProfilePolicy;
use App\Modules\Mechanics\Privacy\MechanicPersonalData;
use App\Modules\Mechanics\Services\EndorsementRatingSource;
use App\Modules\Mechanics\Services\EndorsementService;
use App\Modules\Mechanics\Services\MechanicApprovalService;
use App\Modules\Mechanics\Services\MechanicConsoleCounters;
use App\Modules\Mechanics\Services\MechanicDirectory;
use App\Modules\Mechanics\Services\MechanicProfileService;
use App\Modules\Privacy\Support\PersonalDataRegistry;
use App\Modules\Ratings\Services\RatingSourceRegistry;
use App\Support\Console\ConsoleCounters;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Reference\ReferenceList;
use App\Support\Reference\ReferenceRegistry;
use Illuminate\Support\Facades\Gate;

/**
 * Mechanics module — profiles, approval, endorsements and the public directory.
 *
 * Three ideas hold it together.
 *
 * A profile is invisible until a human has approved it. Not merely unlisted —
 * the directory, the profile page, the API and the rating feed all start from
 * MechanicProfile::scopePubliclyVisible(), and MechanicApprovalService is the
 * only way a profile's status ever changes. That is what lets the rest of the
 * platform treat "this mechanic is listed" as meaning MonaFind checked the
 * qualification.
 *
 * There are two badges and they are independent. "MonaFind approved" is
 * staff's, and it is the status. "Endorsed by X" is a shop's, one row per
 * shop, and a mechanic may hold any number. Staff cannot endorse and shops
 * cannot approve — EndorsementPolicy has no staff override precisely because
 * one would quietly collapse the second badge into the first.
 *
 * And an endorsement is a rating source. Ratings left a seam for exactly this
 * — a resolver per kind of rateable thing, registered by whoever owns it —
 * so mechanic reviews needed no change to that module at all. See
 * EndorsementRatingSource for what an endorsement does and does not entitle.
 */
class MechanicsServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->singleton(MechanicProfileService::class);
        $this->app->singleton(MechanicApprovalService::class);
        $this->app->singleton(EndorsementService::class);
        $this->app->singleton(MechanicDirectory::class);
    }

    protected function bootModule(): void
    {
        $this->registerPersonalData();
        $this->app->make(ConsoleCounters::class)->register(MechanicConsoleCounters::class);

        $this->registerReferenceLists();

        Gate::policy(MechanicProfile::class, MechanicProfilePolicy::class);
        Gate::policy(MechanicEndorsement::class, EndorsementPolicy::class);

        $this->registerRatingSource();
    }

    /**
     * Teach Ratings what an endorsement is.
     *
     * Resolved lazily rather than in registerModule(), because the registry
     * is Ratings' singleton and a module must not reach into another one's
     * bindings before every module has registered.
     */
    private function registerRatingSource(): void
    {
        $this->app->make(RatingSourceRegistry::class)->register(new EndorsementRatingSource);
    }

    /**
     * The speciality list, and this module's own pointers at other lists.
     *
     * Specialities are the one reference list held through a pivot, which is
     * why the link below names the column it is unique with: a mechanic
     * listed under both halves of a duplicated speciality must come out of
     * the merge holding it once, not twice.
     */
    private function registerReferenceLists(): void
    {
        $references = $this->app->make(ReferenceRegistry::class);

        $references->register(new ReferenceList(
            key: 'specialities',
            label: 'Mechanic specialities',
            singular: 'speciality',
            model: MechanicSpeciality::class,
            owner: 'Mechanics',
            note: 'What a mechanic says they do, and what buyers filter the directory by.',
        ));

        $references->link(
            'specialities',
            'mechanic_profile_speciality',
            'mechanic_speciality_id',
            uniqueWith: 'mechanic_profile_id',
        );

        $references->link('provinces', 'mechanic_profiles', 'province_id');
        $references->link('cities', 'mechanic_profiles', 'city_id');
    }

    /**
     * Tell Privacy what personal data this module holds.
     *
     * The module owns the answer because the module owns the tables. Privacy
     * orchestrates export and erasure; it never reads these models itself.
     */
    private function registerPersonalData(): void
    {
        $this->app->make(PersonalDataRegistry::class)->register(MechanicPersonalData::class);
    }
}
