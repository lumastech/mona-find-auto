<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Models\AuditLog;
use App\Modules\Admin\Models\Announcement;
use App\Modules\Admin\Models\ContentPage;
use App\Modules\Admin\Policies\AnnouncementPolicy;
use App\Modules\Admin\Policies\AuditLogPolicy;
use App\Modules\Admin\Policies\ContentPagePolicy;
use App\Modules\Admin\Policies\ReferenceConsolePolicy;
use App\Modules\Admin\Services\AnnouncementBoard;
use App\Modules\Admin\Services\PublishedPolicyDocuments;
use App\Modules\Privacy\Contracts\PolicyDocuments;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * Admin module — the staff console itself.
 *
 * Every other module contributes its own queue to /admin. What this one owns
 * is the parts that belong to no single module: the dashboard that reads all
 * of their counters, the settings screen that configures them, the
 * consolidated reference-data console, the CMS pages the platform publishes
 * about itself, staff management, and the audit viewer.
 *
 * It deliberately holds no knowledge of any of them. The dashboard's numbers
 * arrive through App\Support\Console\ConsoleCounters and the reference lists
 * through App\Support\Reference\ReferenceRegistry — both registries the other
 * modules write to — so adding a tenth queue or a seventh curated list is a
 * change inside the module that owns it, not here.
 */
class AdminServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->singleton(AnnouncementBoard::class);

        /*
         * Privacy stamps a policy version onto every consent record and asks
         * for it through its own interface rather than reading these pages.
         * Admin is registered before Privacy, and Privacy's own fallback is a
         * `bindIf`, so this binding is the one that survives.
         */
        $this->app->bind(PolicyDocuments::class, PublishedPolicyDocuments::class);
    }

    protected function bootModule(): void
    {
        $this->registerPolicies();
        $this->registerGates();
    }

    private function registerPolicies(): void
    {
        Gate::policy(ContentPage::class, ContentPagePolicy::class);
        Gate::policy(Announcement::class, AnnouncementPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
    }

    /**
     * The reference console spans six lists owned by three modules, so its
     * question is about the act rather than about any one model. Gates rather
     * than a policy, for the same reason `moderate` and `finance` are.
     */
    private function registerGates(): void
    {
        Gate::define('curate-reference-data', [ReferenceConsolePolicy::class, 'view']);
        Gate::define('curate-reference-data-manage', [ReferenceConsolePolicy::class, 'manage']);
        Gate::define('merge-reference-data', [ReferenceConsolePolicy::class, 'merge']);
    }
}
