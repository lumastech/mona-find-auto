<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated account.
 */
class AccountController extends Controller
{
    /**
     * Show the account behind the current API token.
     */
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::ok([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'roles' => $user->getRoleNames()->values()->all(),
        ]);
    }
}
