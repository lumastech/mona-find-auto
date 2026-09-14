<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Modules\Sellers\Concerns\ResolvesCurrentSeller;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Models\SellerVerificationEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What a seller is told about their own verification.
 *
 * This screen is the answer to "where is my application?", so it carries the
 * status, what happens next, the reviewer's history, and — when the answer is
 * no — the reason and what to fix.
 */
class VerificationController extends Controller
{
    use ResolvesCurrentSeller;

    public function show(Request $request): Response
    {
        $seller = $this->currentSeller($request);
        $status = $seller->verification_status;

        return Inertia::render('seller/Verification', [
            'status' => [
                'value' => $status->value,
                'label' => $status->label(),
                'guidance' => $status->sellerGuidance(),
                'verified' => $status->isVerified(),
                'public_label' => $status->publicLabel(),
                'in_queue' => $status->isInQueue(),
            ],
            'seller' => [
                'business_name' => $seller->business_name,
                'registration_number' => $seller->registration_number,
                'submitted_at' => $seller->submitted_at?->toIso8601String(),
                'verified_at' => $seller->verified_at?->toIso8601String(),
                'inspection_scheduled_for' => $seller->inspection_scheduled_for?->toIso8601String(),
                'rejection_reason' => $seller->rejection_reason,
            ],
            /*
             * The one thing a seller can do about their own verification: a
             * badge cannot be granted without a registration number, so an
             * application missing one is stuck until they add it.
             */
            'blockers' => $this->blockersFor($seller),
            'history' => $seller->verificationEvents()->with('actor:id,name')->get()
                ->map(static function (SellerVerificationEvent $event): array {
                    $actor = $event->actor;

                    return [
                        'id' => $event->id,
                        'summary' => $event->summary(),
                        'status' => $event->to_status->value,
                        'reason' => $event->reason,
                        /* Reviewer notes are internal; the seller sees the decision, not the working. */
                        'actor' => $actor === null ? 'MonaFind' : $actor->name,
                        'created_at' => $event->created_at->toIso8601String(),
                    ];
                })->all(),
        ]);
    }

    /**
     * What is standing between this seller and a badge.
     *
     * @return array<int, string>
     */
    private function blockersFor(Seller $seller): array
    {
        $blockers = [];

        if (! $seller->mayBeVerified()) {
            $blockers[] = __('Add your business registration number. We cannot grant the Verified badge without it.');
        }

        foreach ($seller->missingPolicies() as $policy) {
            $blockers[] = __('Publish your :policy.', ['policy' => strtolower($policy->label())]);
        }

        foreach ($seller->missingDocuments() as $document) {
            $blockers[] = __('Upload your :document.', ['document' => strtolower($document->label())]);
        }

        if (! $seller->payoutAccounts()->whereNotNull('lenco_recipient_id')->exists()) {
            $blockers[] = __('Add a payout account we can confirm with your bank or mobile-money provider.');
        }

        return $blockers;
    }
}
