<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\LocationDirectory;
use App\Modules\Mechanics\Http\Resources\MechanicProfileResource;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Services\MechanicDirectory;
use App\Modules\Mechanics\Support\DirectoryFilters;
use App\Modules\Ratings\Enums\ReportReason;
use App\Modules\Ratings\Services\RatingFeed;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The public mechanic directory and one mechanic's page.
 *
 * Open to guests like the rest of the storefront, and server-rendered so
 * search engines index it. The only part a guest does not get is the contact
 * block: labels and a masked shape, with a prompt to log in.
 *
 * Both actions go through MechanicDirectory rather than querying the model,
 * because the "approved profiles only" rule belongs in one place — and
 * `show` applies it directly as well, with a 404 rather than a 403. Whether
 * somebody's application was rejected or is still being read is not a
 * visitor's business.
 */
class MechanicDirectoryController extends Controller
{
    public function __construct(
        private readonly MechanicDirectory $directory,
        private readonly LocationDirectory $locations,
        private readonly RatingFeed $reviews,
    ) {}

    public function index(Request $request): Response
    {
        $filters = DirectoryFilters::fromRequest($request);

        $mechanics = $this->directory->search($filters)
            ->through(fn (MechanicProfile $profile): array => MechanicProfileResource::make($profile)->resolve($request));

        return Inertia::render('storefront/mechanics/Index', [
            'mechanics' => $mechanics,
            'filters' => $filters->toArray(),
            'specialities' => $this->directory->specialityOptions(),
            'provinces' => $this->locations->provincesWithCities(),
        ]);
    }

    public function show(Request $request, MechanicProfile $mechanic): Response
    {
        /*
         * A profile that is not approved does not exist as far as the
         * storefront is concerned — including to its own author, who reads
         * it on the application page instead.
         */
        abort_unless($mechanic->isPubliclyVisible(), HttpResponse::HTTP_NOT_FOUND);

        $mechanic = $this->directory->withRatings($mechanic);

        $mechanic->load([
            'province',
            'city',
            'specialities',
            'workHistory',
            'activeEndorsements.seller',
            'media',
        ]);

        return Inertia::render('storefront/mechanics/Show', [
            'mechanic' => MechanicProfileResource::make($mechanic)->resolve($request),
            /*
             * The same feed a seller's page uses, filtered to public
             * directions by the scope inside it — so a mechanic's private
             * rating of a buyer can never surface here.
             */
            'reviews' => $this->reviews->publicFor($mechanic, $request),
            'reportReasons' => ReportReason::options(),
        ]);
    }
}
