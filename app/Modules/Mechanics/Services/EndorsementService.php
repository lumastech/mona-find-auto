<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Services;

use App\Models\User;
use App\Modules\Mechanics\Enums\EndorsementStatus;
use App\Modules\Mechanics\Events\EndorsementRequested;
use App\Modules\Mechanics\Events\EndorsementStatusChanged;
use App\Modules\Mechanics\Exceptions\EndorsementNotAllowed;
use App\Modules\Mechanics\Models\MechanicEndorsement;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Notifications\EndorsementDecided;
use App\Modules\Mechanics\Notifications\EndorsementRequestReceived;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Support\Facades\DB;

/**
 * Asking a shop to vouch for you, and the shop's answer.
 *
 * One rule runs through all of it: only the shop a request was addressed to
 * can answer it. That is checked here rather than only in a policy, because
 * an endorsement is the thing a buyer trusts — a badge saying "Endorsed by
 * Kabwata Motors" has to mean Kabwata Motors said so — and a guard that lives
 * only on the HTTP layer protects a route rather than the rule.
 *
 * Nothing is deleted. Declining, revoking and asking again all move the same
 * row, so the badge is a status test and the history of a relationship stays
 * in one place. It also means a Seller→Mechanic rating written while an
 * endorsement stood keeps the source that entitled it after the endorsement
 * is withdrawn — see EndorsementRatingSource.
 */
class EndorsementService
{
    /**
     * A mechanic asks a shop.
     *
     * @throws EndorsementNotAllowed
     */
    public function request(
        MechanicProfile $profile,
        Seller $seller,
        User $actor,
        ?string $message = null,
    ): MechanicEndorsement {
        /*
         * A draft or suspended shop is not a business a buyer can see, so a
         * badge naming it would point at nothing.
         */
        if (! $seller->verification_status->isPubliclyVisible()) {
            throw EndorsementNotAllowed::sellerNotListed();
        }

        $endorsement = MechanicEndorsement::query()
            ->where('mechanic_profile_id', $profile->getKey())
            ->where('seller_id', $seller->getKey())
            ->first();

        if ($endorsement !== null && ! $endorsement->status->canTransitionTo(EndorsementStatus::Requested)) {
            throw $endorsement->status->awaitsSeller()
                ? EndorsementNotAllowed::alreadyPending()
                : EndorsementNotAllowed::between($endorsement->status, EndorsementStatus::Requested);
        }

        $from = $endorsement?->status;

        $endorsement = DB::transaction(function () use ($endorsement, $profile, $seller, $actor, $message): MechanicEndorsement {
            if ($endorsement === null) {
                return MechanicEndorsement::query()->create([
                    'mechanic_profile_id' => $profile->getKey(),
                    'seller_id' => $seller->getKey(),
                    'status' => EndorsementStatus::Requested,
                    'message' => $message,
                    'requested_by' => $actor->getKey(),
                    'requested_at' => now(),
                ]);
            }

            /*
             * Asking again reuses the row, and clears the last answer with
             * it: a shop reading a fresh request should not see the note they
             * wrote when they turned this person down in March.
             */
            $endorsement->forceFill([
                'status' => EndorsementStatus::Requested,
                'message' => $message,
                'requested_by' => $actor->getKey(),
                'requested_at' => now(),
                'response_note' => null,
                'decided_by' => null,
                'decided_at' => null,
                'revoked_at' => null,
                'revocation_reason' => null,
            ])->save();

            return $endorsement;
        });

        audit(
            $actor,
            'mechanic.endorsement.requested',
            $endorsement,
            $from === null ? null : ['status' => $from->value],
            ['status' => EndorsementStatus::Requested->value, 'seller_id' => $seller->getKey()],
        );

        $endorsement->refresh()->load(['profile', 'seller']);

        $seller->user->notify(new EndorsementRequestReceived($endorsement));

        EndorsementRequested::dispatch($endorsement);

        return $endorsement;
    }

    /**
     * The shop says yes.
     *
     * @throws EndorsementNotAllowed
     */
    public function endorse(
        MechanicEndorsement $endorsement,
        Seller $seller,
        User $actor,
        ?string $note = null,
    ): MechanicEndorsement {
        return $this->decide($endorsement, $seller, $actor, EndorsementStatus::Endorsed, $note, [
            'endorsed_at' => now(),
            'revoked_at' => null,
            'revocation_reason' => null,
        ]);
    }

    /**
     * The shop says no.
     *
     * @throws EndorsementNotAllowed
     */
    public function decline(
        MechanicEndorsement $endorsement,
        Seller $seller,
        User $actor,
        ?string $note = null,
    ): MechanicEndorsement {
        return $this->decide($endorsement, $seller, $actor, EndorsementStatus::Declined, $note);
    }

    /**
     * The shop takes it back.
     *
     * A reason is required where declining's note is optional: withdrawing an
     * endorsement removes a badge a buyer may already have chosen a mechanic
     * on, so somebody has to say why, permanently.
     *
     * @throws EndorsementNotAllowed
     */
    public function revoke(
        MechanicEndorsement $endorsement,
        Seller $seller,
        User $actor,
        string $reason,
    ): MechanicEndorsement {
        if (! $endorsement->isAddressedTo($seller)) {
            throw EndorsementNotAllowed::notAddressee();
        }

        if (! $endorsement->status->isActive()) {
            throw EndorsementNotAllowed::notEndorsed();
        }

        return $this->apply($endorsement, $actor, EndorsementStatus::Revoked, $reason, [
            'revoked_at' => now(),
            'revocation_reason' => $reason,
            'decided_by' => $actor->getKey(),
            'decided_at' => now(),
        ]);
    }

    /**
     * Endorse or decline: the same checks, a different destination.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws EndorsementNotAllowed
     */
    private function decide(
        MechanicEndorsement $endorsement,
        Seller $seller,
        User $actor,
        EndorsementStatus $to,
        ?string $note,
        array $attributes = [],
    ): MechanicEndorsement {
        if (! $endorsement->isAddressedTo($seller)) {
            throw EndorsementNotAllowed::notAddressee();
        }

        if (! $endorsement->status->canTransitionTo($to)) {
            throw EndorsementNotAllowed::alreadyDecided($endorsement->status);
        }

        return $this->apply($endorsement, $actor, $to, $note, [
            ...$attributes,
            'response_note' => $note,
            'decided_by' => $actor->getKey(),
            'decided_at' => now(),
        ]);
    }

    /**
     * Persist a decided move, audit it, tell the mechanic, announce it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function apply(
        MechanicEndorsement $endorsement,
        User $actor,
        EndorsementStatus $to,
        ?string $reason,
        array $attributes,
    ): MechanicEndorsement {
        $from = $endorsement->status;

        DB::transaction(function () use ($endorsement, $to, $attributes): void {
            $endorsement->forceFill([...$attributes, 'status' => $to])->save();
        });

        audit(
            $actor,
            'mechanic.endorsement.'.$to->value,
            $endorsement,
            ['status' => $from->value],
            ['status' => $to->value],
            $reason,
        );

        $endorsement->refresh()->load(['profile.user', 'seller']);

        $endorsement->profile->user->notify(new EndorsementDecided($endorsement, $to, $reason));

        EndorsementStatusChanged::dispatch($endorsement, $from, $to, $reason, $actor);

        return $endorsement;
    }
}
