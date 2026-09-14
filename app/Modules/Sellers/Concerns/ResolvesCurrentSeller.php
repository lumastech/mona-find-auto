<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Concerns;

use App\Models\User;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shop behind a seller-portal request.
 *
 * Every portal controller starts the same way — find the signed-in account's
 * shop, or refuse. A seller-role account with no shop is a broken state rather
 * than a permissions question, which is why it is a 404 and not a redirect
 * back into a wizard the person has already finished.
 */
trait ResolvesCurrentSeller
{
    /**
     * @throws AuthenticationException
     */
    protected function currentSeller(Request $request): Seller
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $seller = $user->seller;

        abort_if($seller === null, Response::HTTP_NOT_FOUND, 'This account does not run a shop on MonaFind.');

        /* The gate still runs: holding the seller role and owning this shop are not the same thing. */
        abort_unless($user->can('manage', $seller), Response::HTTP_FORBIDDEN);

        return $seller;
    }
}
