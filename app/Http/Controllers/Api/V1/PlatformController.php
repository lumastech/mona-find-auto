<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Support\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;

/**
 * Platform metadata.
 */
class PlatformController extends Controller
{
    /**
     * Describe the platform.
     *
     * Returns the currency, display timezone, API version and the settings
     * marked safe to expose, so a client can configure itself in one call.
     */
    public function __invoke(SettingsRepository $settings): JsonResponse
    {
        return ApiResponse::ok([
            'name' => config('app.name'),
            'api_version' => 'v1',
            'currency' => config('monafind.currency'),
            'timezone' => config('monafind.display_timezone'),
            'settings' => $settings->publicValues(),
        ]);
    }
}
