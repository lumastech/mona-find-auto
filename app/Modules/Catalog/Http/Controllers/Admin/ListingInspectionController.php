<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Requests\Admin\InspectionRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ListingInspectionService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The Inspected/Uninspected badge.
 *
 * Its own controller rather than a field on the moderation form, because it
 * is a different decision from publishing: a listing can be perfectly
 * publishable and uninspected, and marking one Inspected is MonaFind
 * vouching for the part itself. Every change writes an audit row with the
 * reason the reviewer gave.
 */
class ListingInspectionController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly ListingInspectionService $inspection) {}

    public function update(InspectionRequest $request, Product $product): RedirectResponse
    {
        $status = $request->inspectionStatus();

        $this->inspection->setStatus(
            $product,
            $status,
            $this->currentUser($request),
            $request->string('reason')->toString(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Listing marked :status.', ['status' => strtolower($status->label())]),
        ]);

        return back();
    }
}
