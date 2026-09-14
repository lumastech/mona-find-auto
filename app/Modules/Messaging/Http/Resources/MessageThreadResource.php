<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Resources;

use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\MessageThreadParticipant;
use App\Modules\Messaging\Services\ThreadSubjectRegistry;
use App\Modules\Messaging\Support\ConversationName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A conversation, from one person's side of it.
 *
 * `counterpart` is the OTHER party, resolved against the viewer, because that
 * is what an inbox row shows and computing it in the template means every
 * view that lists threads gets it slightly differently.
 *
 * `allows_contact_details` is sent so the composer can say, before somebody
 * types a phone number, that it will be removed. Telling them afterwards —
 * with the number already gone — reads as the platform having lost their
 * message.
 *
 * Messages are included only when they have been loaded: an inbox listing
 * twenty threads has no business sending twenty conversations with them.
 *
 * @mixin MessageThread
 */
class MessageThreadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $participant = $viewer === null ? null : $this->participantFor($viewer);
        $counterpart = $viewer === null ? null : $this->counterpartTo($viewer);

        return [
            'id' => $this->id,
            'subject_label' => $this->subject_label,
            'subject_type' => $this->subjectKey(),
            'subject_url' => $this->subjectUrl(),

            'my_role' => $participant?->role->value,
            'counterpart' => $counterpart === null ? null : $this->party($counterpart),

            'messages_count' => $this->messages_count,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'is_unread' => $viewer !== null && $this->isUnreadFor($viewer),
            'is_closed' => $this->isClosed(),

            /*
             * Whether the composer should warn before somebody types a number
             * into it. False until the thread's order is paid.
             */
            'allows_contact_details' => $this->allowsContactDetails(),

            'messages' => MessageResource::collection(
                $this->whenLoaded('messages'),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function party(MessageThreadParticipant $participant): array
    {
        return [
            'id' => $participant->user_id,
            'name' => $participant->role->isTrader()
                ? ConversationName::forOrPlatform($participant->user)
                : $participant->user->name,
            'role' => $participant->role->value,
            'role_label' => $participant->role->label(),
        ];
    }

    /**
     * A short name for the kind of thing this is about — "order", "quotation"
     * — so the UI can pick an icon without parsing a class name.
     */
    private function subjectKey(): string
    {
        return class_basename($this->subject_type);
    }

    private function subjectUrl(): ?string
    {
        $subject = $this->subject;

        if ($subject === null) {
            return null;
        }

        return app(ThreadSubjectRegistry::class)->find($subject)?->url($subject);
    }
}
