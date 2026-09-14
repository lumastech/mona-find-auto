<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Services;

use App\Models\User;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Events\SellerVerificationChanged;
use App\Modules\Sellers\Exceptions\InvalidVerificationTransition;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Models\SellerVerificationEvent;
use App\Support\Roles\Role;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The verification workflow: submitted → under review → inspection → verified
 * or rejected.
 *
 * Every move is checked against the transitions declared on VerificationStatus,
 * writes an event row for the reviewer's history and an audit row for the
 * permanent trail, and announces itself so Search, Catalog and Messaging can
 * react. There is no way to change the status that skips this class.
 */
class SellerVerificationService
{
    /**
     * The seller sends their application in. This is also the moment they get
     * the seller role: policies, payout details and documents all have to be
     * manageable while the application is being reviewed, so portal access
     * comes with applying, not with being approved.
     */
    public function submit(Seller $seller, ?User $actor = null): Seller
    {
        $seller = $this->transitionTo($seller, VerificationStatus::Submitted, $actor, [
            'submitted_at' => now(),
            'rejection_reason' => null,
        ]);

        $seller->user->assignRole(Role::Seller->value);

        return $seller;
    }

    /**
     * A moderator picks the file up.
     */
    public function beginReview(Seller $seller, User $actor, ?string $note = null): Seller
    {
        return $this->transitionTo($seller, VerificationStatus::UnderReview, $actor, [], $note);
    }

    /**
     * Somebody is going out to see the premises.
     */
    public function scheduleInspection(Seller $seller, User $actor, CarbonInterface $when, ?string $note = null): Seller
    {
        return $this->transitionTo(
            $seller,
            VerificationStatus::InspectionScheduled,
            $actor,
            ['inspection_scheduled_for' => $when],
            $note,
        );
    }

    /**
     * Grant the badge.
     *
     * @param  array<string, bool>  $checklist  Which checks the reviewer ticked.
     *
     * @throws InvalidVerificationTransition when the business has no registration number
     */
    public function verify(Seller $seller, User $actor, ?string $note = null, array $checklist = []): Seller
    {
        if (! $seller->mayBeVerified()) {
            throw InvalidVerificationTransition::withoutRegistrationNumber();
        }

        return $this->transitionTo(
            $seller,
            VerificationStatus::Verified,
            $actor,
            [
                'verified_at' => now(),
                'verified_by' => $actor->getKey(),
                'rejection_reason' => null,
            ],
            $note,
            null,
            $checklist,
        );
    }

    /**
     * Turn the application down. The reason is shown to the seller, so it has
     * to say what to fix rather than only that something is wrong.
     */
    public function reject(Seller $seller, User $actor, string $reason, ?string $note = null): Seller
    {
        return $this->transitionTo(
            $seller,
            VerificationStatus::Rejected,
            $actor,
            ['rejection_reason' => $reason, 'verified_at' => null, 'verified_by' => null],
            $note,
            $reason,
        );
    }

    /**
     * Take a verified seller down. Their listings go with them.
     */
    public function suspend(Seller $seller, User $actor, string $reason): Seller
    {
        return $this->transitionTo(
            $seller,
            VerificationStatus::Suspended,
            $actor,
            [],
            null,
            $reason,
        );
    }

    /**
     * Put a suspended seller back.
     */
    public function reinstate(Seller $seller, User $actor, string $reason): Seller
    {
        return $this->transitionTo(
            $seller,
            VerificationStatus::Verified,
            $actor,
            ['verified_at' => now(), 'verified_by' => $actor->getKey()],
            null,
            $reason,
        );
    }

    /**
     * Apply a move: check it is legal, persist it, record it twice — once for
     * the reviewer's history and once for the permanent audit trail — and
     * announce it.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, bool>  $checklist
     *
     * @throws InvalidVerificationTransition
     */
    private function transitionTo(
        Seller $seller,
        VerificationStatus $to,
        ?User $actor,
        array $attributes = [],
        ?string $note = null,
        ?string $reason = null,
        array $checklist = [],
    ): Seller {
        $from = $seller->verification_status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidVerificationTransition::between($from, $to);
        }

        DB::transaction(function () use ($seller, $to, $attributes, $note, $reason, $checklist, $actor, $from): void {
            $seller->forceFill([
                ...$attributes,
                'verification_status' => $to,
                'verification_note' => $note ?? $seller->verification_note,
            ])->save();

            SellerVerificationEvent::query()->create([
                'seller_id' => $seller->getKey(),
                'from_status' => $from,
                'to_status' => $to,
                'note' => $note,
                'reason' => $reason,
                'checklist' => $checklist === [] ? null : $checklist,
                'actor_id' => $actor?->getKey(),
                'created_at' => now(),
            ]);
        });

        audit(
            $actor,
            'seller.verification.'.$to->value,
            $seller,
            ['verification_status' => $from->value],
            ['verification_status' => $to->value],
            $reason,
            ['note' => $note, 'checklist' => $checklist],
        );

        SellerVerificationChanged::dispatch($seller, $from, $to, $reason, $actor);

        return $seller;
    }
}
