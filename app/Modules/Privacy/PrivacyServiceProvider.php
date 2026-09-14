<?php

declare(strict_types=1);

namespace App\Modules\Privacy;

use App\Modules\Privacy\Contracts\PolicyDocuments;
use App\Modules\Privacy\Jobs\ProcessDueErasures;
use App\Modules\Privacy\Models\ErasureRequest;
use App\Modules\Privacy\Policies\ErasureRequestPolicy;
use App\Modules\Privacy\Services\AccountEraser;
use App\Modules\Privacy\Services\ConsentRecorder;
use App\Modules\Privacy\Services\ErasureGuard;
use App\Modules\Privacy\Services\ErasureRequestService;
use App\Modules\Privacy\Services\PersonalDataExporter;
use App\Modules\Privacy\Support\PersonalDataRegistry;
use App\Modules\Privacy\Support\UnpublishedPolicyDocuments;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schedule;

/**
 * Privacy module — consent, data-subject access, and erasure.
 *
 * What the Zambia Data Protection Act 2021 requires of a controller, in one
 * place: proof of what each person agreed to, a copy of their data on demand,
 * and the ability to make them unidentifiable when they ask.
 *
 * ## It owns the process, not the data
 *
 * This module does not know what personal data lives in Orders or Messaging,
 * and must not: a privacy module that read thirteen other modules' tables
 * would be stale the first time any of them gained a column, and would
 * violate the boundary every other module respects.
 *
 * Instead there are two registries that modules write into from their own
 * providers — `PersonalDataRegistry` for export and erasure,
 * `ErasureGuard` for the reasons an erasure has to wait. Adding personal
 * data to a module means adding to that module's source. Nothing here
 * changes, and nothing here can silently miss it — the erasure test walks the
 * registry and every registered source is exercised.
 *
 * ## Registered last
 *
 * Privacy is last in config/modules.php so that every module has had a chance
 * to register its source before the registry is read. Nothing reads it during
 * boot, so the order is belt and braces rather than a requirement — but a
 * module registered after the thing that iterates it is a bug waiting for
 * somebody to add an eager read.
 */
class PrivacyServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        /*
         * Singletons because modules write to them from their own providers,
         * and a fresh instance per resolution would hand the exporter an
         * empty registry.
         */
        $this->app->singleton(PersonalDataRegistry::class);
        $this->app->singleton(ErasureGuard::class);

        /*
         * Which version of the privacy notice is in force is Admin's
         * business, and Admin binds its own implementation. `bindIf` rather
         * than `bind` because Privacy registers LAST — a plain bind here
         * would run after Admin's and quietly replace it, which is the kind
         * of bug that shows up months later as consent records with no
         * version stamped on them.
         *
         * Where nothing has bound it, consent records carry a null version —
         * the truth on a deployment whose content pages were never seeded.
         */
        $this->app->bindIf(PolicyDocuments::class, UnpublishedPolicyDocuments::class);

        $this->app->singleton(ConsentRecorder::class);
        $this->app->singleton(PersonalDataExporter::class);
        $this->app->singleton(ErasureRequestService::class);
        $this->app->singleton(AccountEraser::class);
    }

    protected function bootModule(): void
    {
        Gate::policy(ErasureRequest::class, ErasureRequestPolicy::class);

        $this->registerSchedule();
    }

    /**
     * The nightly sweep.
     *
     * Runs in the small hours because an erasure closes an account and
     * revokes its sessions, and doing that to somebody mid-checkout is
     * avoidable. Off-peak in Africa/Lusaka rather than UTC: the deadline a
     * person was given was in their own time.
     */
    private function registerSchedule(): void
    {
        Schedule::job(new ProcessDueErasures)
            ->dailyAt('02:30')
            ->timezone(config('monafind.display_timezone'))
            ->name('privacy:process-due-erasures')
            ->withoutOverlapping();
    }
}
