<?php

declare(strict_types=1);

namespace App\Modules\Messaging;

use App\Modules\Messaging\Channels\SmsChannel;
use App\Modules\Messaging\Contracts\NotificationRouter;
use App\Modules\Messaging\Events\MessagePosted;
use App\Modules\Messaging\Listeners\NotifyParticipantsOfMessage;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Policies\MessageThreadPolicy;
use App\Modules\Messaging\Privacy\MessagingPersonalData;
use App\Modules\Messaging\Services\MessagingEnquiryChannel;
use App\Modules\Messaging\Services\NotificationMatrix;
use App\Modules\Messaging\Services\NotificationPreferenceService;
use App\Modules\Messaging\Services\PreferenceAwareRouter;
use App\Modules\Messaging\Services\Subjects\ListingThreadSubject;
use App\Modules\Messaging\Services\Subjects\OrderThreadSubject;
use App\Modules\Messaging\Services\Subjects\QuotationThreadSubject;
use App\Modules\Messaging\Services\Subjects\SellerThreadSubject;
use App\Modules\Messaging\Services\ThreadService;
use App\Modules\Messaging\Services\ThreadSubjectRegistry;
use App\Modules\Privacy\Support\PersonalDataRegistry;
use App\Modules\Shopping\Contracts\SellerEnquiryChannel;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Messaging module — notifications, SMS and conversations.
 *
 * Two halves that turn out to be one thing.
 *
 * The first is the notification layer every other module has been queueing
 * against since Identity. Modules were each deciding their own `via()`, which
 * worked while there were two of them and does not survive a person who wants
 * fewer texts: there was no way to reach a decision spread across fifteen
 * classes. Now a notification declares WHAT it is — one case of
 * NotificationEvent — and this module decides WHERE it goes, through the
 * staff matrix, then the recipient's preferences, then whether they can
 * actually be reached that way. SMS becomes a channel in that scheme rather
 * than a hand-rolled queued job per message, which is what makes "turn off
 * order texts" a thing a person can do at all.
 *
 * The second is threads: conversations hung off a listing, a quote request or
 * an order. They matter because of what they prevent. A buyer and a seller
 * who cannot ask each other a question on the platform will ask it on
 * WhatsApp, and then a dispute has to be decided on two accounts of a
 * conversation neither can produce. So the thread is where the question goes,
 * the order's thread is what a moderator may read when a dispute is opened,
 * and — before payment — contact details are screened out of it exactly as
 * they are out of a review.
 *
 * What connects the halves: a message is news, so posting one sends a
 * notification, which routes through the first half. That is the whole of it.
 *
 * ## What this module hands to other modules
 *
 * `PlatformNotification` + `NotificationEvent` (a notification names its
 * event), and `SellerEnquiryChannel`, which Shopping declared and stubbed
 * months ago against exactly this arrival. Nothing here reaches into another
 * module's internals; the subject resolvers below know about orders, quotes
 * and listings in the same way Ratings' OrderRatingSource knows about orders.
 */
class MessagingServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->singleton(NotificationMatrix::class);
        $this->app->singleton(NotificationPreferenceService::class);
        $this->app->singleton(ThreadService::class);

        /*
         * One router per request. It caches each person's opt-outs, and a
         * single order state change asks about two people — so a fresh
         * instance per notification would be a query per channel per
         * recipient to send one message.
         */
        $this->app->singleton(NotificationRouter::class, PreferenceAwareRouter::class);

        $this->app->singleton(SmsChannel::class);

        /*
         * One registry per process, seeded with the four things a
         * conversation can be about. A fifth is a class and one more line
         * here — the pattern Ratings uses for rateable sources.
         */
        $this->app->singleton(ThreadSubjectRegistry::class, function (): ThreadSubjectRegistry {
            $registry = new ThreadSubjectRegistry;

            $registry->register(new ListingThreadSubject);
            $registry->register(new SellerThreadSubject);
            $registry->register(new QuotationThreadSubject);
            $registry->register(new OrderThreadSubject);

            return $registry;
        });

        /*
         * Shopping shipped StoredEnquiryChannel so that "Contact seller"
         * kept its promise before threads existed. This is the
         * implementation it was holding the seam open for; the storefront
         * does not change.
         */
        $this->app->bind(SellerEnquiryChannel::class, MessagingEnquiryChannel::class);
    }

    protected function bootModule(): void
    {
        $this->registerPersonalData();
        Gate::policy(MessageThread::class, MessageThreadPolicy::class);

        Event::listen(MessagePosted::class, NotifyParticipantsOfMessage::class);

        $this->shareCounts();
    }

    /**
     * Put the two unread numbers on every page.
     *
     * The bell is in all three headers, so the count has to be shared rather
     * than passed by each controller — a badge that is only right on the
     * pages that remembered to send it is a badge nobody believes. The same
     * reasoning put Shopping's cart and wishlist counts here.
     *
     * Guests resolve to null rather than to zero: there is no bell to draw.
     */
    private function shareCounts(): void
    {
        Inertia::share('messaging', function (Request $request): ?array {
            $user = $request->user();

            if ($user === null) {
                return null;
            }

            return [
                'unread_notifications' => $user->unreadNotifications()->count(),
                'unread_threads' => app(ThreadService::class)->unreadCountFor($user),
            ];
        });
    }

    /**
     * Tell Privacy what personal data this module holds.
     *
     * The module owns the answer because the module owns the tables. Privacy
     * orchestrates export and erasure; it never reads these models itself.
     */
    private function registerPersonalData(): void
    {
        $this->app->make(PersonalDataRegistry::class)->register(MessagingPersonalData::class);
    }
}
