<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\Admin\StoreAnnouncementRequest;
use App\Modules\Admin\Models\Announcement;
use App\Modules\Admin\Services\AnnouncementBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Scheduling the banner across the top of an area.
 *
 * Every write here drops the board's cache, so a banner going up or coming
 * down is immediate. That matters more than it sounds: the reason somebody is
 * on this screen at all is usually that something is on fire.
 */
class AnnouncementController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly AnnouncementBoard $board) {}

    public function store(StoreAnnouncementRequest $request): RedirectResponse
    {
        Gate::authorize('create', Announcement::class);

        $attributes = [
            ...$request->validated(),
            'is_active' => (bool) ($request->validated('is_active') ?? true),
            'created_by' => $this->currentUser($request)->getKey(),
        ];

        $announcement = Announcement::query()->create($attributes);

        $this->board->flush();

        audit($this->currentUser($request), 'announcement.created', $announcement, null, $attributes);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Announcement scheduled.')]);

        return back();
    }

    public function update(StoreAnnouncementRequest $request, Announcement $announcement): RedirectResponse
    {
        Gate::authorize('update', Announcement::class);

        $before = $announcement->only(['title', 'body', 'level', 'audience', 'starts_at', 'ends_at', 'is_active']);

        $announcement->forceFill([
            ...$request->validated(),
            'is_active' => (bool) ($request->validated('is_active') ?? true),
        ])->save();

        $this->board->flush();

        audit($this->currentUser($request), 'announcement.updated', $announcement, $before, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Announcement saved.')]);

        return back();
    }

    /**
     * Take a banner down now, whatever its window says.
     *
     * Deactivating rather than deleting: a banner that turned out to be wrong
     * is something somebody will want to read back afterwards, and the
     * announcement that was up during an outage is part of the record of it.
     */
    public function deactivate(Request $request, Announcement $announcement): RedirectResponse
    {
        Gate::authorize('update', Announcement::class);

        $announcement->forceFill(['is_active' => false])->save();

        $this->board->flush();

        audit($this->currentUser($request), 'announcement.deactivated', $announcement, ['is_active' => true], ['is_active' => false]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Announcement taken down.')]);

        return back();
    }
}
