<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Enums\BodyType;
use App\Modules\Catalog\Models\VehicleModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Curating the models under each make.
 *
 * The production years are what let the listing form offer a sane year range
 * instead of every year since 1950, so they are worth keeping accurate even
 * though nothing is enforced against them.
 */
class VehicleModelController extends Controller
{
    use InteractsWithCurrentUser;

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage', VehicleModel::class);

        $validated = $this->validated($request);

        $model = VehicleModel::query()->create($validated);

        audit($this->currentUser($request), 'catalog.vehicle_model.created', $model, null, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $model->name])]);

        return back();
    }

    public function update(Request $request, VehicleModel $vehicleModel): RedirectResponse
    {
        Gate::authorize('manage', VehicleModel::class);

        $before = $vehicleModel->only(['make_id', 'name', 'body_type', 'production_start_year', 'production_end_year', 'is_popular', 'is_active']);
        $validated = $this->validated($request, $vehicleModel);

        $vehicleModel->update($validated);

        audit($this->currentUser($request), 'catalog.vehicle_model.updated', $vehicleModel, $before, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name saved.', ['name' => $vehicleModel->name])]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?VehicleModel $model = null): array
    {
        $currentYear = (int) date('Y');

        /*
         * The unique index is on the slug, not the name, so "E-Class" and
         * "E Class" have to collide. Deriving it before validation is what
         * makes the rule check the thing the database actually enforces.
         */
        $request->merge(['slug' => Str::slug((string) $request->input('name'))]);

        $validated = $request->validate([
            'make_id' => ['required', 'integer', Rule::exists('makes', 'id')],
            'name' => ['required', 'string', 'max:80'],
            'slug' => [
                'required',
                'string',
                /* Slugs are unique per make: "Corolla" belongs to Toyota alone. */
                Rule::unique('vehicle_models', 'slug')
                    ->where('make_id', (int) $request->input('make_id'))
                    ->ignore($model),
            ],
            'body_type' => ['nullable', Rule::enum(BodyType::class)],
            'production_start_year' => ['nullable', 'integer', 'min:1950', 'max:'.($currentYear + 1)],
            'production_end_year' => ['nullable', 'integer', 'gte:production_start_year', 'max:'.($currentYear + 1)],
            'is_popular' => ['boolean'],
            'is_active' => ['boolean'],
        ], [
            'slug.unique' => 'That make already has a model with this name.',
        ]);

        return $validated;
    }
}
