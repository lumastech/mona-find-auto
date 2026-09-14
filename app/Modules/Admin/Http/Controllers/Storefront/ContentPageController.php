<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\ContentPage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A published page, at its own slug.
 *
 * Route-model bound on the slug and then checked again: binding finds a draft
 * just as happily as a live page, and a draft is something staff are still
 * writing.
 */
class ContentPageController extends Controller
{
    public function show(ContentPage $page): Response
    {
        $version = $page->liveVersion();

        /*
         * Both halves matter. A draft is something staff are still writing,
         * and a published page with no version behind it would render blank —
         * neither is a page, so neither is a 200.
         */
        abort_unless($page->isPublished() && $version !== null, 404);

        return Inertia::render('storefront/content/Show', [
            'page' => [
                'slug' => $page->slug,
                'title' => $version->title,
                'body' => $version->body,
                'meta_description' => $page->meta_description,
                'version' => $version->version,
                'updated_at' => $version->published_at?->toIso8601String(),
            ],
        ]);
    }
}
