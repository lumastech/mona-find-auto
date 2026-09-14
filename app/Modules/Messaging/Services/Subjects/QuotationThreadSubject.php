<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services\Subjects;

use App\Modules\Messaging\Contracts\ThreadSubject;
use App\Modules\Messaging\Support\ThreadParties;
use App\Modules\Shopping\Enums\QuotationStatus;
use App\Modules\Shopping\Models\Quotation;
use Illuminate\Database\Eloquent\Model;

/**
 * A request for a quote, as something to hold a conversation about.
 *
 * The most useful thread on the platform and the one the brief is really
 * about: a buyer describes a part, a shop needs to know which engine, and
 * without somewhere to ask that, the price comes back wrong or not at all.
 *
 * Both sides are known from the row, so the participants need no help. A
 * quote is never itself paid — accepting one produces a cart line and then an
 * order, and that order gets its own thread — so contact details stay
 * screened here throughout.
 */
class QuotationThreadSubject implements ThreadSubject
{
    /**
     * @return class-string<Model>
     */
    public function handles(): string
    {
        return Quotation::class;
    }

    public function parties(Model $subject): ThreadParties
    {
        /** @var Quotation $subject */
        return ThreadParties::buyerAndSeller($subject->buyer, $subject->seller);
    }

    public function label(Model $subject): string
    {
        return $subject instanceof Quotation
            ? 'Quote request: '.$subject->product->name
            : 'Quote request';
    }

    public function isPaid(Model $subject): bool
    {
        return false;
    }

    /**
     * A declined or expired quote is finished. An accepted one is finished
     * here too — the conversation continues on the order it became, which is
     * where both sides can also see what they are owed.
     */
    public function isClosed(Model $subject): bool
    {
        return $subject instanceof Quotation && in_array($subject->status, [
            QuotationStatus::Declined,
            QuotationStatus::Expired,
            QuotationStatus::Accepted,
        ], true);
    }

    public function url(Model $subject): string
    {
        return route('quotations.index');
    }
}
