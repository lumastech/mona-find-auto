<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Models\User;
use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Events\ListingPublished;
use App\Modules\Catalog\Events\ListingRejected;
use App\Modules\Catalog\Events\ListingStatusChanged;
use App\Modules\Catalog\Events\ListingSubmittedForReview;
use App\Modules\Catalog\Exceptions\InvalidListingTransition;
use App\Modules\Catalog\Exceptions\ListingNotReadyForReview;
use App\Modules\Catalog\Exceptions\SellerMayNotList;
use App\Modules\Catalog\Models\ListingReviewEvent;
use App\Modules\Catalog\Models\Product;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Support\Facades\DB;

/**
 * The listing lifecycle: draft → pending review → published, and everything
 * that can go wrong on the way.
 *
 * Every move is checked against the transitions ListingStatus declares,
 * written to the listing's own review history, recorded in the immutable
 * audit trail, and announced. Nothing else changes `status` — the same shape
 * the Sellers module uses for verification, and for the same reason: a status
 * that can be written from anywhere is a status nobody can reason about.
 */
class ListingModerationService
{
    /**
     * Send a listing to the moderation queue.
     *
     * Two gates, in this order. First the shop: a rejected or suspended
     * seller may keep writing drafts but may not put stock in front of
     * buyers. Then the listing itself, checked field by field so the seller
     * is told what to fix rather than that something is wrong.
     *
     * @throws SellerMayNotList
     * @throws ListingNotReadyForReview
     * @throws InvalidListingTransition
     */
    public function submit(Product $product, ?User $actor = null): Product
    {
        $this->guardSellerMayList($product->seller);

        $problems = $this->readinessProblems($product);

        if ($problems !== []) {
            throw ListingNotReadyForReview::because($problems);
        }

        $isResubmission = $product->status === ListingStatus::Rejected;

        $product = $this->transitionTo($product, ListingStatus::PendingReview, $actor, [
            'submitted_at' => now(),
            /* The old reasons are cleared: they belong to a version that no longer exists. */
            'rejection_reason' => null,
            'rejection_fields' => null,
        ]);

        ListingSubmittedForReview::dispatch($product, $actor, $isResubmission);

        return $product;
    }

    /**
     * Approve a listing and put it on the storefront.
     *
     * @throws InvalidListingTransition
     */
    public function publish(Product $product, User $actor, ?string $note = null): Product
    {
        $product = $this->transitionTo($product, ListingStatus::Published, $actor, [
            'published_at' => now(),
            'unpublished_at' => null,
            'rejection_reason' => null,
            'rejection_fields' => null,
            'reviewed_by' => $actor->getKey(),
            'reviewed_at' => now(),
        ], $note);

        ListingPublished::dispatch($product, $actor);

        return $product;
    }

    /**
     * Turn a listing down.
     *
     * The per-field reasons are the point of this method. A seller told
     * "rejected" resubmits the same listing; a seller told "photos: too dark
     * to see the part" fixes the photos.
     *
     * @param  array<string, string>  $fieldReasons  Keyed by form field.
     *
     * @throws InvalidListingTransition
     */
    public function reject(Product $product, User $actor, string $reason, array $fieldReasons = [], ?string $note = null): Product
    {
        $product = $this->transitionTo(
            $product,
            ListingStatus::Rejected,
            $actor,
            [
                'rejection_reason' => $reason,
                'rejection_fields' => $fieldReasons === [] ? null : $fieldReasons,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'published_at' => null,
            ],
            $note,
            $reason,
            $fieldReasons,
        );

        ListingRejected::dispatch($product, $reason, $fieldReasons, $actor);

        return $product;
    }

    /**
     * Take a listing off the storefront without retiring it.
     *
     * The actor is null when the platform did it on its own — which is how a
     * suspended seller's stock disappears.
     *
     * @throws InvalidListingTransition
     */
    public function unpublish(Product $product, ?User $actor, string $reason): Product
    {
        return $this->transitionTo(
            $product,
            ListingStatus::Unpublished,
            $actor,
            ['unpublished_at' => now()],
            null,
            $reason,
        );
    }

    /**
     * Put a previously approved listing back on the storefront.
     *
     * No second review: it was approved once and nothing about it changed
     * while it was down. A listing that has never been published cannot get
     * here — Unpublished is only reachable from Published.
     *
     * @throws InvalidListingTransition
     */
    public function republish(Product $product, ?User $actor, ?string $reason = null): Product
    {
        $product = $this->transitionTo(
            $product,
            ListingStatus::Published,
            $actor,
            ['published_at' => now(), 'unpublished_at' => null],
            null,
            $reason,
        );

        ListingPublished::dispatch($product, $actor);

        return $product;
    }

    /**
     * Retire a listing for good. Order history still points at it.
     *
     * @throws InvalidListingTransition
     */
    public function archive(Product $product, ?User $actor, ?string $reason = null): Product
    {
        return $this->transitionTo(
            $product,
            ListingStatus::Archived,
            $actor,
            ['archived_at' => now()],
            null,
            $reason,
        );
    }

    /**
     * Pull a listing back out of the queue, so the seller can edit it again.
     *
     * @throws InvalidListingTransition
     */
    public function withdraw(Product $product, ?User $actor): Product
    {
        return $this->transitionTo($product, ListingStatus::Draft, $actor, ['submitted_at' => null]);
    }

    /**
     * Take down everything a seller has live.
     *
     * Called when a shop is suspended: taking the shop down has to take its
     * stock with it, and doing it here rather than by filtering on read means
     * an order can never be placed against a listing that only looks gone.
     *
     * @return int How many listings were pulled.
     */
    public function unpublishAllFor(Seller $seller, ?User $actor, string $reason): int
    {
        $pulled = 0;

        Product::query()
            ->where('seller_id', $seller->getKey())
            ->whereIn('status', [ListingStatus::Published, ListingStatus::PendingReview])
            ->cursor()
            ->each(function (Product $product) use ($actor, $reason, &$pulled): void {
                /*
                 * A listing still in the queue has never been published, so
                 * Unpublished is not a state it may enter. Withdrawing it
                 * puts it back in the seller's hands, which is where it
                 * belongs while their shop is down.
                 */
                if ($product->status === ListingStatus::PendingReview) {
                    $this->withdraw($product, $actor);
                } else {
                    $this->unpublish($product, $actor, $reason);
                }

                $pulled++;
            });

        return $pulled;
    }

    /**
     * What is still missing before a listing can be reviewed, keyed by the
     * form field the seller has to go and fix.
     *
     * @return array<string, string>
     */
    public function readinessProblems(Product $product): array
    {
        $problems = [];

        if ($product->getMedia(Product::PHOTOS_COLLECTION)->count() < Product::MIN_PHOTOS) {
            $problems['photos'] = 'Add at least one photo of the part.';
        }

        if ($product->variants()->count() === 0) {
            $problems['price'] = 'Give this listing a price.';
        }

        if ($product->variants()->where('price', '<=', 0)->exists()) {
            $problems['price'] = 'Every option needs a price above zero.';
        }

        if (blank($product->description)) {
            $problems['description'] = 'Describe the part so a buyer knows what they are getting.';
        }

        return $problems;
    }

    /**
     * Whether this shop is allowed to put stock in front of buyers.
     *
     * Verified sellers can, and so can those still waiting on a decision —
     * a queue that takes days should not stop a new shop from preparing its
     * catalogue. A rejected or suspended shop cannot.
     */
    public function sellerMayList(Seller $seller): bool
    {
        return in_array($seller->verification_status, [
            VerificationStatus::Submitted,
            VerificationStatus::UnderReview,
            VerificationStatus::InspectionScheduled,
            VerificationStatus::Verified,
        ], true);
    }

    /**
     * @throws SellerMayNotList
     */
    private function guardSellerMayList(Seller $seller): void
    {
        if (! $this->sellerMayList($seller)) {
            throw SellerMayNotList::inStatus($seller);
        }
    }

    /**
     * Apply a move: check it is legal, persist it, record it twice — once in
     * the listing's own history for the seller and once in the immutable
     * audit trail — and announce it.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, string>  $fieldReasons
     *
     * @throws InvalidListingTransition
     */
    private function transitionTo(
        Product $product,
        ListingStatus $to,
        ?User $actor,
        array $attributes = [],
        ?string $note = null,
        ?string $reason = null,
        array $fieldReasons = [],
    ): Product {
        $from = $product->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidListingTransition::between($from, $to);
        }

        DB::transaction(function () use ($product, $to, $attributes, $note, $reason, $fieldReasons, $actor, $from): void {
            $product->forceFill([...$attributes, 'status' => $to])->save();

            ListingReviewEvent::query()->create([
                'product_id' => $product->getKey(),
                'from_status' => $from,
                'to_status' => $to,
                'reason' => $reason,
                'field_reasons' => $fieldReasons === [] ? null : $fieldReasons,
                'note' => $note,
                'actor_id' => $actor?->getKey(),
                'created_at' => now(),
            ]);
        });

        audit(
            $actor,
            'listing.'.$to->value,
            $product,
            ['status' => $from->value],
            ['status' => $to->value],
            $reason,
            ['note' => $note, 'field_reasons' => $fieldReasons],
        );

        ListingStatusChanged::dispatch($product, $from, $to, $reason, $actor);

        return $product;
    }
}
