<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Http\Resources;

use App\Models\User;
use App\Modules\Mechanics\Models\MechanicEndorsement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * One endorsement, as the shop or the mechanic sees it.
 *
 * This is a private view — the seller portal's queue and the mechanic's own
 * list — not the public badge. The badge is the short block inside
 * MechanicProfileResource, which carries only the shop's name and the date,
 * because the message a mechanic wrote when asking is between the two of them.
 *
 * @mixin MechanicEndorsement
 */
class MechanicEndorsementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MechanicEndorsement $endorsement */
        $endorsement = $this->resource;

        $viewer = $request->user();
        $viewer = $viewer instanceof User ? $viewer : null;

        return [
            'id' => $endorsement->getKey(),
            'status' => $endorsement->status->value,
            'status_label' => $endorsement->status->label(),
            'status_variant' => $endorsement->status->badgeVariant(),

            'mechanic' => [
                'id' => $endorsement->profile->getKey(),
                'slug' => $endorsement->profile->slug,
                'display_name' => $endorsement->profile->display_name,
                'headline' => $endorsement->profile->headline,
                'qualification' => $endorsement->profile->qualification,
                'years_experience' => $endorsement->profile->years_experience,
                'locality' => $endorsement->profile->locality(),
                'approved' => $endorsement->profile->isApproved(),
                'avatar_url' => $endorsement->profile->getFirstMediaUrl('avatar') ?: null,
            ],

            'seller' => [
                'id' => $endorsement->seller->getKey(),
                'slug' => $endorsement->seller->slug,
                'business_name' => $endorsement->seller->business_name,
                'verified' => $endorsement->seller->isVerified(),
            ],

            'message' => $endorsement->message,
            'response_note' => $endorsement->response_note,
            'revocation_reason' => $endorsement->revocation_reason,

            'requested_at' => $endorsement->requested_at?->toIso8601String(),
            'decided_at' => $endorsement->decided_at?->toIso8601String(),
            'endorsed_at' => $endorsement->endorsed_at?->toIso8601String(),
            'revoked_at' => $endorsement->revoked_at?->toIso8601String(),

            /* What this viewer may do about it, so the UI does not guess. */
            'can' => [
                'decide' => $viewer !== null && Gate::forUser($viewer)->allows('decide', $endorsement),
                'revoke' => $viewer !== null && Gate::forUser($viewer)->allows('revoke', $endorsement),
            ],
        ];
    }
}
