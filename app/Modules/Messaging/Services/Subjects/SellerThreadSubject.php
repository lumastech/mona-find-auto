<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services\Subjects;

use App\Modules\Messaging\Contracts\ThreadSubject;
use App\Modules\Messaging\Support\ThreadParties;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A shop itself, as something to hold a conversation about.
 *
 * The general enquiry — "do you fit tyres?", "are you open on Saturday?" —
 * which is not about any one listing. It exists mainly so that "contact this
 * shop" with no listing in hand is a conversation rather than a crash; the
 * storefront reaches it from a shopfront page rather than from a product.
 *
 * Like a listing thread it is never paid and never closes, so its messages
 * are screened throughout.
 */
class SellerThreadSubject implements ThreadSubject
{
    /**
     * @return class-string<Model>
     */
    public function handles(): string
    {
        return Seller::class;
    }

    public function parties(Model $subject): ThreadParties
    {
        throw new RuntimeException(
            'A shop conversation needs the buyer who started it; use ThreadService::openWith().',
        );
    }

    public function label(Model $subject): string
    {
        return $subject instanceof Seller ? $subject->business_name : 'Shop';
    }

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
        return $subject instanceof Seller
            ? route('sellers.show', $subject)
            : url('/');
    }
}
