<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Enums\AnnouncementAudience;
use App\Modules\Admin\Enums\AnnouncementLevel;
use App\Modules\Admin\Http\Requests\Admin\PublishContentPageRequest;
use App\Modules\Admin\Http\Requests\Admin\UnpublishContentPageRequest;
use App\Modules\Admin\Models\Announcement;
use App\Modules\Admin\Models\ContentPage;
use App\Modules\Admin\Models\ContentPageVersion;
use App\Modules\Admin\Services\ContentPageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The pages MonaFind publishes about itself.
 *
 * The index doubles as the announcements screen because the two are the same
 * job — what the platform is telling people right now — and splitting them
 * would put the banner announcing a change on a different screen from the
 * page describing it.
 */
class ContentPageController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly ContentPageService $pages) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ContentPage::class);

        return Inertia::render('admin/content/Index', [
            'pages' => ContentPage::query()
                ->with('currentVersion:id,version,published_at')
                ->orderByDesc('is_system')
                ->orderBy('position')
                ->orderBy('title')
                ->get()
                ->map(fn (ContentPage $page): array => [
                    'id' => $page->id,
                    'slug' => $page->slug,
                    'title' => $page->title,
                    'status' => $page->status->value,
                    'status_label' => $page->status->label(),
                    'is_system' => $page->is_system,
                    'is_platform_terms' => $page->isPlatformTerms(),
                    'show_in_footer' => $page->show_in_footer,
                    'version' => $page->currentVersion?->version,
                    'published_at' => $page->currentVersion?->published_at?->toIso8601String(),
                    'href' => route('admin.content.edit', $page),
                    'can_publish' => $request->user()?->can('publish', $page) ?? false,
                ])
                ->all(),
            'announcements' => $this->announcements(),
            'announcementOptions' => [
                'levels' => AnnouncementLevel::options(),
                'audiences' => AnnouncementAudience::options(),
            ],
            'canManageAnnouncements' => $request->user()?->can('create', Announcement::class) ?? false,
        ]);
    }

    public function edit(Request $request, ContentPage $page): Response
    {
        Gate::authorize('view', $page);

        $page->load('versions.author:id,name');

        /* Null on a page nobody has written yet; the editor opens empty. */
        $current = $page->liveVersion();

        return Inertia::render('admin/content/Edit', [
            'page' => [
                'id' => $page->id,
                'slug' => $page->slug,
                'title' => $page->title,
                'status' => $page->status->value,
                'is_system' => $page->is_system,
                'is_platform_terms' => $page->isPlatformTerms(),
                'show_in_footer' => $page->show_in_footer,
                'position' => $page->position,
                'meta_description' => $page->meta_description,
                'body' => $current === null ? '' : $current->body,
                'version' => $current?->version,
                'public_href' => $page->isPublished() ? route('pages.show', $page) : null,
            ],
            'versions' => $page->versions->map(fn (ContentPageVersion $version): array => [
                'id' => $version->id,
                'version' => $version->version,
                'title' => $version->title,
                'change_note' => $version->change_note,
                'author' => $version->authorName(),
                'published_at' => $version->published_at?->toIso8601String(),
                'is_current' => $version->id === $page->current_version_id,
            ])->all(),
            'canPublish' => $request->user()?->can('publish', $page) ?? false,
            /*
             * Shown on the terms page only, because it is the one page whose
             * publication changes something outside itself.
             */
            'termsNotice' => $page->isPlatformTerms()
                ? __('Publishing this page bumps the platform terms version. Buyers checking out afterwards accept the new version, and every past acceptance keeps pointing at the version it was made against.')
                : null,
        ]);
    }

    public function publish(PublishContentPageRequest $request, ContentPage $page): RedirectResponse
    {
        Gate::authorize('publish', $page);

        $version = $this->pages->publish(
            page: $page,
            title: (string) $request->validated('title'),
            body: (string) $request->validated('body'),
            actor: $this->currentUser($request),
            changeNote: (string) $request->validated('change_note'),
            goLive: $request->shouldGoLive(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $request->shouldGoLive()
                ? __('Version :version published.', ['version' => $version->version])
                : __('Version :version saved as a draft.', ['version' => $version->version]),
        ]);

        return back();
    }

    public function unpublish(UnpublishContentPageRequest $request, ContentPage $page): RedirectResponse
    {
        Gate::authorize('publish', $page);

        $this->pages->unpublish($page, $this->currentUser($request), $request->reason());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Page taken down.')]);

        return back();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function announcements(): array
    {
        return Announcement::query()
            ->orderByDesc('starts_at')
            ->limit(50)
            ->get()
            ->map(static fn (Announcement $announcement): array => [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'level' => $announcement->level->value,
                'level_label' => $announcement->level->label(),
                'audience' => $announcement->audience->value,
                'audience_label' => $announcement->audience->label(),
                'link_url' => $announcement->link_url,
                'link_label' => $announcement->link_label,
                'starts_at' => $announcement->starts_at->toIso8601String(),
                'ends_at' => $announcement->ends_at?->toIso8601String(),
                'is_active' => $announcement->is_active,
                'is_showing' => $announcement->isShowing(),
                'has_ended' => $announcement->hasEnded(),
            ])
            ->all();
    }
}
