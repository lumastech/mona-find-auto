<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Http\Controllers\Api;

use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Mechanics\Http\Resources\MechanicProfileResource;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Services\MechanicDirectory;
use App\Modules\Mechanics\Support\DirectoryFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The public mechanic directory for the mobile app.
 *
 * The same service the web directory uses, so a filter cannot mean one thing
 * here and another there — and so "approved profiles only" is applied in one
 * place rather than twice.
 *
 * Bound by id rather than slug: the mobile app holds ids.
 */
class MechanicController extends Controller
{
    public function __construct(private readonly MechanicDirectory $directory) {}

    /**
     * Browse mechanics.
     *
     * Guests may list and read them, exactly as they may browse the
     * storefront. Contact details follow the same blur rule as the web page.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = DirectoryFilters::fromRequest($request);

        $mechanics = $this->directory->search($filters)
            ->through(fn (MechanicProfile $profile): array => MechanicProfileResource::make($profile)->resolve($request));

        return ApiResponse::paginated($mechanics, [
            'filters' => $filters->toArray(),
            'specialities' => $this->directory->specialityOptions(),
        ]);
    }

    /**
     * One mechanic's public profile.
     */
    public function show(Request $request, MechanicProfile $mechanic): JsonResponse
    {
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

        return ApiResponse::ok(MechanicProfileResource::make($mechanic)->resolve($request));
    }

    /**
     * The controlled speciality list, for an app building its own filter bar.
     */
    public function specialities(): JsonResponse
    {
        return ApiResponse::ok($this->directory->specialityOptions());
    }
}
