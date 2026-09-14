<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Services;

use App\Models\User;
use App\Modules\Sellers\Enums\PaymentMode;
use App\Modules\Sellers\Enums\RegistrationStep;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Events\SellerRegistered;
use App\Modules\Sellers\Exceptions\RegistrationIncomplete;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Models\SellerRegistrationDraft;
use Illuminate\Support\Facades\DB;

/**
 * The seller sign-up wizard.
 *
 * Signing up asks for a bank account, a PACRA certificate and an ID — things
 * almost nobody has on the desk in front of them — so the wizard is resumable
 * and every step is saved the moment it is finished.
 *
 * From step two onwards the answers are held on a real Seller row in Draft
 * status rather than in the draft's JSON, because steps three to five attach
 * things to it: policies belong to a seller, a payout account belongs to a
 * seller, and a document is uploaded against one. A Draft seller is invisible
 * to buyers and to the verification queue until it is submitted.
 */
class SellerRegistrationService
{
    public function __construct(
        private readonly SellerPolicyService $policies,
        private readonly SellerVerificationService $verification,
    ) {}

    /**
     * The applicant's draft, started if this is their first visit.
     */
    public function draftFor(User $user): SellerRegistrationDraft
    {
        return SellerRegistrationDraft::query()->firstOrCreate(
            ['user_id' => $user->getKey()],
            [
                'current_step' => RegistrationStep::first(),
                'furthest_step' => RegistrationStep::first(),
                'data' => [],
            ],
        );
    }

    /**
     * Record one step's answers and move the applicant to the next.
     *
     * @param  array<string, mixed>  $values
     */
    public function saveStep(
        SellerRegistrationDraft $draft,
        RegistrationStep $step,
        array $values,
        ?User $actor = null,
    ): SellerRegistrationDraft {
        DB::transaction(function () use ($draft, $step, $values, $actor): void {
            $draft->putStep($step, $values);
            $draft->current_step = $step->next() ?? $step;
            $draft->save();

            /*
             * Business details are the point the application becomes a real
             * record: everything after this step is attached to a seller.
             */
            if ($step === RegistrationStep::Business) {
                $this->syncSeller($draft);
            }

            /*
             * The policy step is the one place a step's answers are not just
             * kept on the draft. Policies are versioned records a buyer will
             * accept at checkout, so saving the step publishes them.
             */
            if ($step === RegistrationStep::Policies) {
                $this->publishPoliciesFrom($draft, $values, $actor);
            }
        });

        return $draft->refresh();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function publishPoliciesFrom(SellerRegistrationDraft $draft, array $values, ?User $actor): void
    {
        $seller = $draft->seller;
        $bodies = $values['policies'] ?? [];

        if ($seller === null || ! is_array($bodies)) {
            return;
        }

        /** @var array<string, string|null> $bodies */
        $this->policies->publishMany($seller, $bodies, $actor);
    }

    /**
     * Move back to a step already answered, without losing anything.
     */
    public function moveTo(SellerRegistrationDraft $draft, RegistrationStep $step): SellerRegistrationDraft
    {
        if ($draft->canOpen($step)) {
            $draft->forceFill(['current_step' => $step])->save();
        }

        return $draft;
    }

    /**
     * Send the application to MonaFind.
     *
     * @throws RegistrationIncomplete
     */
    public function submit(SellerRegistrationDraft $draft, User $actor): Seller
    {
        $seller = $draft->seller;

        if ($seller === null) {
            throw RegistrationIncomplete::atStep(RegistrationStep::Business);
        }

        $this->assertComplete($seller);

        $seller = $this->verification->submit($seller, $actor);

        $draft->forceFill(['submitted_at' => now(), 'current_step' => RegistrationStep::Review])->save();

        SellerRegistered::dispatch($seller);

        return $seller;
    }

    /**
     * Everything that has to be true before an application can be sent.
     *
     * These are checked here rather than only in the form because a draft can
     * be half-finished for weeks: a policy the seller published on Monday can
     * be deleted on Friday, and the wizard's own step validation would never
     * see it.
     *
     * @throws RegistrationIncomplete
     */
    public function assertComplete(Seller $seller): void
    {
        $missingPolicies = $seller->missingPolicies();

        if ($missingPolicies !== []) {
            throw RegistrationIncomplete::missingPolicies(
                array_map(static fn ($type): string => $type->label(), $missingPolicies),
            );
        }

        if (! $seller->payoutAccounts()->whereNotNull('lenco_recipient_id')->exists()) {
            throw RegistrationIncomplete::atStep(RegistrationStep::Payout);
        }

        $missingDocuments = $seller->missingDocuments();

        if ($missingDocuments !== []) {
            throw RegistrationIncomplete::missingDocuments(
                array_map(static fn ($type): string => $type->label(), $missingDocuments),
            );
        }
    }

    /**
     * What the review step shows and what the submit button is enabled by.
     *
     * @return array<string, mixed>
     */
    public function completeness(SellerRegistrationDraft $draft): array
    {
        $seller = $draft->seller;

        return [
            'type' => $draft->sellerType() !== null,
            'business' => $seller !== null,
            'policies' => $seller !== null && $seller->missingPolicies() === [],
            'payout' => $seller !== null && $seller->payoutAccounts()->whereNotNull('lenco_recipient_id')->exists(),
            'documents' => $seller !== null && $seller->missingDocuments() === [],
        ];
    }

    /**
     * Create or update the Draft-status seller behind the application.
     *
     * New sellers always start on the platform's default payment mode with no
     * monetisation override — both are staff-set, and neither is anything the
     * applicant is shown a control for.
     */
    private function syncSeller(SellerRegistrationDraft $draft): void
    {
        $type = $draft->sellerType();
        $business = $draft->step(RegistrationStep::Business);

        if ($type === null || $business === []) {
            return;
        }

        $attributes = [
            'type' => $type,
            'business_name' => $business['business_name'],
            'registration_number' => $business['registration_number'] ?? null,
            'description' => $business['description'] ?? null,
            'province_id' => $business['province_id'],
            'city_id' => $business['city_id'],
            'street' => $business['street'],
            'plot_number' => $business['plot_number'] ?? null,
            'latitude' => $business['latitude'] ?? null,
            'longitude' => $business['longitude'] ?? null,
            'place_id' => $business['place_id'] ?? null,
            'formatted_address' => $business['formatted_address'] ?? null,
            'phone' => $business['phone'],
            'email' => $business['email'],
            'contact_person' => $business['contact_person'],
            'bay_count' => $type->hasWorkshopCapacity() ? ($business['bay_count'] ?? null) : null,
            'opening_hours' => $business['opening_hours'] ?? null,
        ];

        if ($draft->seller !== null) {
            $draft->seller->update($attributes);

            return;
        }

        $seller = Seller::query()->create([
            ...$attributes,
            'user_id' => $draft->user_id,
            'verification_status' => VerificationStatus::Draft,
            'payment_mode' => PaymentMode::default(),
            'monetisation_policy_id' => null,
        ]);

        $draft->forceFill(['seller_id' => $seller->getKey()])->save();
        $draft->setRelation('seller', $seller);
    }
}
