<?php

declare(strict_types=1);

namespace App\Modules\Shopping;

use App\Modules\Privacy\Support\PersonalDataRegistry;
use App\Modules\Shopping\Contracts\SellerEnquiryChannel;
use App\Modules\Shopping\Events\QuotationAnswered;
use App\Modules\Shopping\Events\QuotationRequested;
use App\Modules\Shopping\Jobs\ExpireStaleQuotations;
use App\Modules\Shopping\Listeners\NotifyBuyerOfQuotationAnswer;
use App\Modules\Shopping\Listeners\NotifySellerOfQuotationRequest;
use App\Modules\Shopping\Models\Quotation;
use App\Modules\Shopping\Policies\QuotationPolicy;
use App\Modules\Shopping\Privacy\ShoppingPersonalData;
use App\Modules\Shopping\Services\CartService;
use App\Modules\Shopping\Services\QuotationService;
use App\Modules\Shopping\Services\StoredEnquiryChannel;
use App\Modules\Shopping\Services\WishlistService;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schedule;
use Inertia\Inertia;

/**
 * Shopping module — wishlists, carts and quotations.
 *
 * Everything between finding a part and paying for one. The three sit
 * together because they share a single awkward fact: on this platform, time
 * passes between choosing something and buying it. A buyer assembles a repair
 * across several shops over days, and in that time prices move, shelves
 * empty and listings come down — so a wishlist has to be able to say what
 * changed, a cart has to re-check itself on every read, and a negotiated
 * price has to carry an expiry date.
 *
 * Its dependencies are all inward: it reads Catalog listings and Inventory
 * quantities, and it will hand a cart to Orders. Nothing calls into it.
 */
class ShoppingServiceProvider extends ModuleServiceProvider
{
    /**
     * How many saved listing ids ride along on a storefront page.
     *
     * Enough that a real wishlist is covered whole; low enough that a buyer
     * who has saved a thousand parts does not put a thousand integers into
     * every page they load. Past the limit, hearts on the oldest saves render
     * empty until the buyer opens the wishlist itself.
     */
    public const SAVED_ID_LIMIT = 300;

    protected function registerModule(): void
    {
        /*
         * "Contact seller" goes through a contract because the thing behind
         * it is going to change. Messaging owns threads, replies and
         * moderation and is not built yet; until it binds its own
         * implementation, StoredEnquiryChannel keeps the promise the button
         * makes — the seller really does receive the message.
         */
        $this->app->bind(SellerEnquiryChannel::class, StoredEnquiryChannel::class);

        $this->app->singleton(CartService::class);
        $this->app->singleton(WishlistService::class);
        $this->app->singleton(QuotationService::class);
    }

    protected function bootModule(): void
    {
        $this->registerPersonalData();
        $this->registerPolicies();
        $this->registerListeners();
        $this->registerSchedule();
        $this->shareCounts();
    }

    /**
     * Put the buyer's cart count, wishlist count and saved listing ids on
     * every storefront page.
     *
     * The header carries both badges on every screen, so they have to be
     * shared rather than passed by each controller — a count that is only
     * right on the pages that remembered to send it is a count nobody
     * believes.
     *
     * The saved ids are here for the same reason and one more: the heart
     * appears on every product card, and cards are rendered by Catalog,
     * Search and Sellers. Sharing the ids means those modules do not have to
     * learn that wishlists exist — which is the boundary rule, and also the
     * difference between one change here and a change in every controller
     * that lists parts. A wishlist is tens of rows, so it is a small array of
     * integers; SAVED_ID_LIMIT keeps it that way whatever a buyer does.
     *
     * Only inside the storefront: the seller portal and the staff console
     * never resolve the closure.
     */
    private function shareCounts(): void
    {
        Inertia::share('shopping', function (Request $request): ?array {
            if ($request->is('seller', 'seller/*', 'admin', 'admin/*')) {
                return null;
            }

            $user = $request->user();

            $wishlist = app(WishlistService::class);

            return [
                'cart_count' => app(CartService::class)->count($user),
                'wishlist_count' => $wishlist->count($user),
                'saved_product_ids' => $wishlist->savedIds($user, self::SAVED_ID_LIMIT),
            ];
        });
    }

    private function registerPolicies(): void
    {
        Gate::policy(Quotation::class, QuotationPolicy::class);
    }

    /**
     * Both sides of a negotiation hear about it out of band.
     *
     * Neither notification blocks the request that caused it: a buyer
     * pressing "Request a quote" should see their request appear whether or
     * not a mail server is answering.
     */
    private function registerListeners(): void
    {
        Event::listen(QuotationRequested::class, NotifySellerOfQuotationRequest::class);
        Event::listen(QuotationAnswered::class, NotifyBuyerOfQuotationAnswer::class);
    }

    /**
     * Void stale quotes once a day.
     *
     * Early, before the working day: a seller opening their inbox at eight
     * should not see a price they can no longer honour sitting in the
     * unanswered pile. The sweep does not cause the expiry — accepting
     * already refuses a stale quote on its own — it writes it down so the
     * lists and counts stop lying.
     */
    private function registerSchedule(): void
    {
        Schedule::job(new ExpireStaleQuotations)
            ->dailyAt('01:30')
            ->timezone((string) config('monafind.display_timezone', 'Africa/Lusaka'))
            ->name('shopping:expire-stale-quotations')
            ->withoutOverlapping();
    }

    /**
     * Tell Privacy what personal data this module holds.
     *
     * The module owns the answer because the module owns the tables. Privacy
     * orchestrates export and erasure; it never reads these models itself.
     */
    private function registerPersonalData(): void
    {
        $this->app->make(PersonalDataRegistry::class)->register(ShoppingPersonalData::class);
    }
}
