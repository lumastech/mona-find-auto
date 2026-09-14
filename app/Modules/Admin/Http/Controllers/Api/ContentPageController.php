<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Api;

use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\ContentPage;
use Illuminate\Http\JsonResponse;

/**
 * The pages MonaFind publishes about itself, for the mobile app.
 *
 * Not a convenience. Registration collects consent against the CURRENT
 * version of the terms and the privacy notice, and an app that cannot fetch
 * those is an app that asks people to agree to something it cannot show them
 * — which is the thing the Data Protection Act is most specific about.
 *
 * The version number is in the payload for the same reason it is on the
 * consent record: it is what makes "they agreed to this text" checkable.
 */
class ContentPageController extends Controller
{
    /**
     * Every published page, without its body.
     *
     * Bodies are long and an app listing the footer links does not need
     * five of them.
     */
    public function index(): JsonResponse
    {
        $pages = ContentPage::query()
            ->live()
            ->with('currentVersion:id,content_page_id,version,published_at')
            ->orderBy('position')
            ->orderBy('title')
            ->get()
            ->map(static fn (ContentPage $page): array => [
                'slug' => $page->slug,
                'title' => $page->title,
                'meta_description' => $page->meta_description,
                'show_in_footer' => $page->show_in_footer,
                'version' => $page->liveVersion()?->version,
                'published_at' => $page->liveVersion()?->published_at?->toIso8601String(),
            ]);

        return ApiResponse::ok($pages);
    }

    /**
     * One page, with its text.
     *
     * Bound by slug and scoped to published pages, so a draft staff are
     * still writing is a 404 rather than a preview.
     */
    public function show(string $slug): JsonResponse
    {
        $page = ContentPage::query()
            ->live()
            ->with('currentVersion')
            ->where('slug', $slug)
            ->firstOrFail();

        $version = $page->liveVersion();

        return ApiResponse::ok([
            'slug' => $page->slug,
            /* The version's own title where there is one — staff may rename a page between versions. */
            'title' => $version === null ? $page->title : $version->title,
            'body' => $version?->body,
            'meta_description' => $page->meta_description,
            'version' => $version?->version,
            'published_at' => $version?->published_at?->toIso8601String(),
        ]);
    }
}
