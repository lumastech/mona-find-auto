<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Requests\Storefront\AddressRequest;
use App\Modules\Identity\Http\Resources\UserAddressResource;
use App\Modules\Identity\Models\UserAddress;
use App\Modules\Identity\Services\AddressBook;
use App\Modules\Identity\Services\LocationDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A buyer's delivery address book.
 */
class AddressController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly AddressBook $addresses,
        private readonly LocationDirectory $locations,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('settings/Addresses', [
            'addresses' => UserAddressResource::collection(
                $this->currentUser($request)->addresses()->with(['province', 'city'])->get()
            )->resolve(),
            'provinces' => $this->locations->provincesWithCities(),
        ]);
    }

    public function store(AddressRequest $request): RedirectResponse
    {
        $this->addresses->add($this->currentUser($request), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Address saved.')]);

        return to_route('addresses.index');
    }

    public function update(AddressRequest $request, UserAddress $address): RedirectResponse
    {
        $this->addresses->update($address, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Address updated.')]);

        return to_route('addresses.index');
    }

    public function destroy(Request $request, UserAddress $address): RedirectResponse
    {
        Gate::authorize('delete', $address);

        $this->addresses->remove($address);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Address removed.')]);

        return to_route('addresses.index');
    }

    public function makeDefault(Request $request, UserAddress $address): RedirectResponse
    {
        Gate::authorize('update', $address);

        $this->addresses->makeDefault($address);

        return to_route('addresses.index');
    }
}
