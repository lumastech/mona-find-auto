<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Api;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Requests\Storefront\AddressRequest;
use App\Modules\Identity\Http\Resources\UserAddressResource;
use App\Modules\Identity\Models\UserAddress;
use App\Modules\Identity\Services\AddressBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The buyer's address book, mirrored for the mobile app.
 */
class AddressController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly AddressBook $addresses) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::ok(
            UserAddressResource::collection(
                $this->currentUser($request)->addresses()->with(['province', 'city'])->get()
            )
        );
    }

    public function store(AddressRequest $request): JsonResponse
    {
        $address = $this->addresses->add($this->currentUser($request), $request->validated());

        return ApiResponse::created(new UserAddressResource($address->load(['province', 'city'])));
    }

    public function show(Request $request, UserAddress $address): JsonResponse
    {
        Gate::authorize('view', $address);

        return ApiResponse::ok(new UserAddressResource($address->load(['province', 'city'])));
    }

    public function update(AddressRequest $request, UserAddress $address): JsonResponse
    {
        $updated = $this->addresses->update($address, $request->validated());

        return ApiResponse::ok(new UserAddressResource($updated->load(['province', 'city'])));
    }

    public function destroy(Request $request, UserAddress $address): JsonResponse
    {
        Gate::authorize('delete', $address);

        $this->addresses->remove($address);

        return ApiResponse::noContent();
    }
}
