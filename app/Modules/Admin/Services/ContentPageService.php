<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Enums\ContentPageStatus;
use App\Modules\Admin\Models\ContentPage;
use App\Modules\Admin\Models\ContentPageVersion;
use Illuminate\Support\Facades\DB;

/**
 * Writing the pages MonaFind publishes about itself.
 *
 * Everything goes through here because of one page. The platform terms feed
 * `policies.platform_terms_version`, which is copied onto every checkout
 * acceptance — so publishing a new version of that page has to bump that
 * setting in the same transaction. If the two ever disagree, buyers are
 * recorded as having accepted a version number whose text nobody can produce.
 *
 * The other pages ride the same path for free, and get a history nobody has
 * to remember to keep.
 */
class ContentPageService
{
    /**
     * Write a new version and, if the page is live, make it the current one.
     *
     * A draft page accumulates versions without any of them being current:
     * that is what lets somebody rewrite the FAQ over a week and publish it
     * once.
     */
    public function publish(
        ContentPage $page,
        string $title,
        string $body,
        ?User $actor = null,
        ?string $changeNote = null,
        bool $goLive = true,
    ): ContentPageVersion {
        return DB::transaction(function () use ($page, $title, $body, $actor, $changeNote, $goLive): ContentPageVersion {
            $version = $page->versions()->create([
                'version' => $this->nextVersionNumber($page),
                'title' => $title,
                'body' => $body,
                'change_note' => $changeNote,
                'created_by' => $actor?->getKey(),
                'created_by_label' => $actor?->name,
                'published_at' => $goLive ? now() : null,
            ]);

            $before = [
                'status' => $page->status->value,
                'version' => $page->currentVersion?->version,
                'title' => $page->title,
            ];

            if ($goLive) {
                $page->forceFill([
                    'title' => $title,
                    'status' => ContentPageStatus::Published,
                    'current_version_id' => $version->id,
                    'published_at' => $page->published_at ?? now(),
                ])->save();

                $this->syncPlatformTerms($page, $version, $actor, $changeNote);
            }

            audit(
                $actor,
                'content.page.published',
                $page,
                $before,
                ['status' => $page->status->value, 'version' => $version->version, 'title' => $title],
                $changeNote,
            );

            return $version;
        });
    }

    /**
     * Take a page down without deleting anything it ever said.
     */
    public function unpublish(ContentPage $page, ?User $actor, string $reason): ContentPage
    {
        $before = ['status' => $page->status->value];

        $page->forceFill(['status' => ContentPageStatus::Draft])->save();

        audit($actor, 'content.page.unpublished', $page, $before, ['status' => $page->status->value], $reason);

        return $page;
    }

    /**
     * Change the page's placement without touching its words.
     *
     * @param  array{show_in_footer?: bool, position?: int, meta_description?: string|null, slug?: string}  $attributes
     */
    public function updateSettings(ContentPage $page, array $attributes, ?User $actor, ?string $reason = null): ContentPage
    {
        $before = $page->only(['slug', 'show_in_footer', 'position', 'meta_description']);

        /* A system page's slug is read by code elsewhere; it does not move. */
        if ($page->is_system) {
            unset($attributes['slug']);
        }

        $page->forceFill($attributes)->save();

        audit($actor, 'content.page.updated', $page, $before, $attributes, $reason);

        return $page;
    }

    /**
     * Keep the terms settings in step with the terms page.
     *
     * The version number is the page's own version, not a separate counter —
     * a buyer's acceptance records "3", and version 3 of this page is exactly
     * what they were shown.
     */
    private function syncPlatformTerms(
        ContentPage $page,
        ContentPageVersion $version,
        ?User $actor,
        ?string $reason,
    ): void {
        if (! $page->isPlatformTerms()) {
            return;
        }

        settings()->set('policies.platform_terms_version', (string) $version->version, $actor, $reason);
        settings()->set('policies.platform_terms_body', $version->body, $actor, $reason);
    }

    private function nextVersionNumber(ContentPage $page): int
    {
        return (int) $page->versions()->max('version') + 1;
    }
}
