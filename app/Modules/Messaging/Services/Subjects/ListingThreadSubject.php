<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services\Subjects;

use App\Modules\Catalog\Models\Product;
use App\Modules\Messaging\Contracts\ThreadSubject;
use App\Modules\Messaging\Support\ThreadParties;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A listing, as something to hold a conversation about.
 *
 * This is "Contact seller" — the enquiry a buyer sends before there is an
 * order, a quote or anything else to point at. It is the one subject on which
 * nothing has ever been paid, so `isPaid()` is flatly false and every message
 * here goes through the screen.
 *
 * A listing thread never closes. Somebody asking in March whether a part
 * still fits a 2014 Hilux is a live question in March, whatever happened to
 * the conversation in January.
 *
 * It is also the one subject whose participants cannot be read off the
 * subject: a listing belongs to a shop but not to any particular buyer, and
 * which buyer is asking is the caller's news. Hence ThreadService::openWith(),
 * which takes the parties, against ThreadService::openFor(), which asks the
 * resolver for them.
 */
class ListingThreadSubject implements ThreadSubject
{
    /**
     * @return class-string<Model>
     */
    public function handles(): string
    {
        return Product::class;
    }

    public function parties(Model $subject): ThreadParties
    {
        throw new RuntimeException(
            'A listing conversation needs the buyer who started it; use ThreadService::openWith().',
        );
    }

    public function label(Model $subject): string
    {
        return $subject instanceof Product ? $subject->name : 'Listing';
    }

    /**
     * Nothing has been paid for on a listing thread by definition — a paid
     * listing is an order, and the order has its own thread.
     */
    public function isPaid(Model $subject): bool
    {
        return false;
    }

    public function isClosed(Model $subject): bool
    {
        return false;
    }

    public function url(Model $subject): string
    {
        return $subject instanceof Product
            ? route('listings.show', $subject)
            : url('/');
    }
}
