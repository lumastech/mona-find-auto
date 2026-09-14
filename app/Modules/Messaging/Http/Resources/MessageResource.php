<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Resources;

use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Support\ConversationName;
use App\Support\Content\ScreenFlag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One message, as either side reads it.
 *
 * `body` is what was stored, which is what was published — there is no
 * unredacted version to accidentally send. `redactions` carries the LABELS of
 * what was taken out, so the recipient sees "Phone number redacted" rather
 * than an unexplained "[removed]"; a person who cannot tell whether the
 * platform edited the message or the sender typed something odd will go and
 * ask on WhatsApp, which is the outcome the screen exists to avoid.
 *
 * `is_mine` is computed against the viewer rather than sent as the author's
 * id and compared in the template — a chat bubble on the wrong side is a
 * small bug with an outsized effect on whether the thread is readable.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'body' => $this->body,
            'is_mine' => $viewer !== null && $this->isFrom($viewer),
            'is_from_platform' => $this->isFromPlatform(),

            'author' => $this->isFromPlatform() ? null : [
                'id' => $this->author?->getKey(),
                'name' => ConversationName::for($this->author),
            ],

            'was_redacted' => $this->wasRedacted(),
            'redactions' => array_values(array_unique(array_map(
                static fn (ScreenFlag $flag): string => $flag->label(),
                $this->screenFlags(),
            ))),

            'attachments' => $this->getMedia(Message::ATTACHMENTS_COLLECTION)
                ->map(static fn (Media $media): array => [
                    'id' => $media->getKey(),
                    'name' => $media->file_name,
                    'mime_type' => $media->mime_type,
                    'size' => $media->size,
                    'url' => $media->getUrl(),
                    'thumb_url' => $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : null,
                ])
                ->all(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
