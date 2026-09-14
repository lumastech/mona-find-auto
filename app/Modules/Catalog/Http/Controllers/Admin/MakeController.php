<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Make;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Curating the list of vehicle manufacturers.
 *
 * There is no delete: a make with listings behind it cannot go without taking
 * them with it, so retiring one takes it out of every picker and leaves the
 * listings that already point at it intact.
 */
class MakeController extends Controller
{
    use InteractsWithCurrentUser;

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Make::class);

        $validated = $this->validated($request);

        $make = Make::query()->create($validated);

        audit($this->currentUser($request), 'catalog.make.created', $make, null, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $make->name])]);

        return back();
    }

    public function update(Request $request, Make $make): RedirectResponse
    {
        Gate::authorize('manage', Make::class);

        $before = $make->only(['name', 'country', 'is_popular', 'position', 'is_active']);
        $validated = $this->validated($request, $make);

        $make->update($validated);

        audit($this->currentUser($request), 'catalog.make.updated', $make, $before, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name saved.', ['name' => $make->name])]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Make $make = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('makes', 'name')->ignore($make)],
            'country' => ['nullable', 'string', 'max:60'],
            'is_popular' => ['boolean'],
            'position' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['boolean'],
        ]);
    }
}
