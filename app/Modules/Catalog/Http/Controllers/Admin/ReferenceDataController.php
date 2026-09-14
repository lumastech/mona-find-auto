<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Enums\BodyType;
use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Make;
use App\Modules\Catalog\Models\VehicleModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The reference lists staff curate: makes, models and the category tree.
 *
 * These are what stop "Toyota", "TOYOTA" and "Toyata" becoming three makes
 * and the search facets becoming useless. Retiring is the usual answer rather
 * than deleting — existing listings point at these rows, and deleting one
 * would take them with it.
 */
class ReferenceDataController extends Controller
{
    use InteractsWithCurrentUser;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Make::class);

        return Inertia::render('admin/reference/Index', [
            'makes' => Make::query()
                ->withCount('vehicleModels')
                ->orderByDesc('is_popular')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'country', 'is_popular', 'position', 'is_active']),
            'vehicleModels' => VehicleModel::query()
                ->orderBy('make_id')
                ->orderBy('name')
                ->get(['id', 'make_id', 'name', 'slug', 'body_type', 'production_start_year', 'production_end_year', 'is_popular', 'is_active']),
            'categories' => CategoryResource::collection(
                Category::query()->inTreeOrder()->get(),
            )->resolve($request),
            'bodyTypes' => BodyType::options(),
            'maxCategoryDepth' => Category::MAX_DEPTH,
            'canManage' => $request->user()?->can('manage', Make::class) ?? false,
        ]);
    }
}
