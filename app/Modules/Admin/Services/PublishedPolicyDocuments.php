<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\ContentPage;
use App\Modules\Privacy\Contracts\PolicyDocuments;

/**
 * Admin's answer to Privacy's question about policy versions.
 *
 * Privacy has to stamp a version number onto every consent record, and the
 * pages carrying those versions are published here. This is the seam between
 * the two: Privacy declares the interface, Admin implements it, and neither
 * reads the other's models.
 *
 * ## Deliberately not cached
 *
 * An earlier version of this class memoised the page per instance. It was
 * wrong twice over. `ConsentRecorder` is a singleton and holds this object
 * for as long as it lives, so the cache outlives the request it was scoped
 * to — and under a persistent worker it would outlive the deployment,
 * stamping a stale version number onto consents recorded after staff
 * published a new notice.
 *
 * The saving was two queries per registration. A consent record that names
 * the wrong version of the document is not worth two queries.
 */
class PublishedPolicyDocuments implements PolicyDocuments
{
    public function currentVersion(string $slug): ?int
    {
        return $this->page($slug)?->liveVersion()?->version;
    }

    public function url(string $slug): ?string
    {
        return $this->page($slug) === null ? null : route('pages.show', $slug);
    }

    private function page(string $slug): ?ContentPage
    {
        return ContentPage::query()
            ->live()
            ->with('currentVersion:id,content_page_id,version')
            ->where('slug', $slug)
            ->first();
    }
}
