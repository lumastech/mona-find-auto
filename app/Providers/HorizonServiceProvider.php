<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Support\Roles\Role;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Register the Horizon gate.
     *
     * Outside local development the dashboard exposes payloads for payment
     * and payout jobs, so only platform administrators may open it.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user): bool {
            return $user?->hasRole(Role::PlatformAdmin->value) ?? false;
        });
    }
}
