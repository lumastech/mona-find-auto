<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Http\Resources;

use App\Models\User;
use App\Modules\Ratings\Models\Rating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One rating, as whoever is asking is allowed to see it.
 *
 * This resource is the LAST line of the privacy rule rather than the first.
 * Lists are filtered by direction in the query — see Rating::scopePublic() —
 * because a resource can only redact what it has been handed, and the failure
 * that matters is a private rating reaching a JSON list at all. What is
 * decided here is the narrower question of how much of a rating somebody
 * already entitled to see gets: buyers see a review and its reply, sellers
 * additionally see who reported it, staff see the moderation trail.
 *
 * @mixin Rating
 */
class RatingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Rating $rating */
        $rating = $this->resource;

        $viewer = $request->user();
        $isStaff = $viewer instanceof User && Gate::forUser($viewer)->allows('staff');

        return [
            'id' => $rating->getKey(),
            'direction' => $rating->direction->value,
            'is_public' => $rating->direction->isPublic(),
            'stars' => $rating->stars,
            'body' => $rating->body,
            'author' => $rating->authorName(),
            'verified_purchase' => $rating->verified_purchase,
            'created_at' => $rating->created_at?->toIso8601String(),

            'photos' => $rating->getMedia(Rating::PHOTOS_COLLECTION)
                ->map(static fn (Media $media): array => [
                    'id' => $media->getKey(),
                    'url' => $media->getUrl(),
                    'thumb_url' => $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : $media->getUrl(),
                ])
                ->all(),

            'reply' => $rating->hasReply() ? [
                'body' => $rating->reply_body,
                'at' => $rating->replied_at?->toIso8601String(),
            ] : null,

            /* What the viewer may do about it, so the UI does not guess. */
            'can' => [
                'reply' => $viewer instanceof User && Gate::forUser($viewer)->allows('reply', $rating),
                'report' => $viewer instanceof User && Gate::forUser($viewer)->allows('report', $rating),
            ],

            /*
             * The moderation trail is staff-only. A seller seeing "hidden:
             * abusive language" on somebody else's review learns more about
             * that buyer than they are owed.
             */
            ...$isStaff ? $this->staffFields($rating) : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function staffFields(Rating $rating): array
    {
        return [
            'status' => $rating->status->value,
            'status_label' => $rating->status->label(),
            'status_variant' => $rating->status->badgeVariant(),
            'screen_flags' => array_map(
                static fn ($flag): array => ['value' => $flag->value, 'label' => $flag->label()],
                $rating->screenFlags(),
            ),
            'was_redacted' => $rating->wasRedacted(),
            'reports_count' => $rating->reports_count,
            'reports' => $rating->relationLoaded('reports')
                ? $rating->reports->map(static fn ($report): array => [
                    'id' => $report->getKey(),
                    'reason' => $report->reason->value,
                    'reason_label' => $report->reason->label(),
                    'details' => $report->details,
                    'status' => $report->status->value,
                    'status_label' => $report->status->label(),
                    'status_variant' => $report->status->badgeVariant(),
                    'reporter' => $report->reporter->name,
                    'created_at' => $report->created_at?->toIso8601String(),
                ])->all()
                : [],
            'moderation_reason' => $rating->moderation_reason,
            'moderated_by' => $rating->moderator?->name,
            'moderated_at' => $rating->moderated_at?->toIso8601String(),
            'submitted_by' => $rating->submitter->name,
            'source' => [
                'type' => $rating->source->value,
                'label' => $rating->source->label(),
                'id' => $rating->source_id,
            ],
            'subject' => $rating->ratee->getAttribute('business_name') ?? $rating->ratee->getAttribute('name'),
        ];
    }
}
