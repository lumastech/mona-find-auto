<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\Admin\UpdateSettingsRequest;
use App\Modules\Admin\Services\SettingsEditor;
use App\Modules\Admin\Support\SettingsSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The one screen where the platform's own numbers are changed.
 *
 * Platform administrators only, and not because the values are secret — a
 * ranking weight is not — but because of what they reach. Moving one weight
 * re-ranks every search on the platform; changing the reserve percentage
 * changes what every direct-settlement seller is paid. There is no smaller
 * unit of this screen it would make sense to hand to a moderator.
 *
 * Every save carries a reason, and every changed key writes its own audit row
 * through the settings repository, so "who shortened the escrow window in
 * March, and why" is answerable.
 */
class SettingsController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly SettingsSchema $schema,
        private readonly SettingsEditor $editor,
    ) {}

    public function index(): Response
    {
        Gate::authorize('admin-only');

        return Inertia::render('admin/settings/Index', [
            'panels' => $this->schema->panels(),
            /*
             * Said on the screen rather than only in a docblock: somebody
             * looking for the terms text here needs to be sent to the page
             * that owns it, not left assuming it is missing.
             */
            'readOnlyNote' => __('The platform terms are edited as a page under Content, because publishing them bumps the version number recorded against every checkout acceptance.'),
            'termsHref' => route('admin.content.index'),
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        Gate::authorize('admin-only');

        $changed = $this->editor->apply(
            $request->values(),
            $this->currentUser($request),
            $request->reason(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $changed === []
                ? __('No settings changed.')
                : trans_choice('{1}One setting saved.|[2,*]:count settings saved.', count($changed), ['count' => count($changed)]),
        ]);

        return back();
    }
}
