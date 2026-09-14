<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where somebody lands the moment they finish the registration form.
 *
 * Fortify's default is the dashboard, which for a brand-new account means an
 * immediate bounce to the email verification notice — and the SMS code that
 * was just sent would never get a screen. Registration sends the code, so
 * registration hands over to the screen that consumes it.
 */
class RegisterResponse implements RegisterResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', Response::HTTP_CREATED);
        }

        return redirect()->route('phone.verify');
    }
}
