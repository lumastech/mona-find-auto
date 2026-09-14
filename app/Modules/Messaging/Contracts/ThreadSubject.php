<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Contracts;

use App\Modules\Messaging\Support\ThreadParties;
use Illuminate\Database\Eloquent\Model;

/**
 * What one kind of conversable thing knows about itself.
 *
 * Messaging has to answer three questions about anything a thread can hang
 * off — who is in the conversation, what it is called, and whether money has
 * already changed hands — and the answers are entirely different for a
 * listing, a quote request and an order. Rather than branch on the subject
 * type inside the service, each kind ships a resolver and registers it.
 *
 * The same seam as Ratings' RatingSourceResolver, for the same reason: a
 * fourth kind of thread (a mechanic booking, say) is a new class and a
 * register() call, not an edit to the thread service.
 */
interface ThreadSubject
{
    /**
     * The model class this resolver speaks for.
     *
     * @return class-string<Model>
     */
    public function handles(): string;

    /**
     * Who is in a conversation about this, and which side each is on.
     */
    public function parties(Model $subject): ThreadParties;

    /**
     * What the thread is about, in a line — "Toyota Hilux front brake pads"
     * or "Order MFA-1024".
     */
    public function label(Model $subject): string;

    /**
     * Whether the buyer has paid for this.
     *
     * The whole of the redaction rule. Before payment, contact details are
     * screened out of messages the way they are out of reviews; after it, the
     * two sides are entitled to each other's details and the screen steps
     * aside.
     */
    public function isPaid(Model $subject): bool;

    /**
     * Whether this conversation is finished and should be read-only.
     */
    public function isClosed(Model $subject): bool;

    /**
     * Where a participant goes to read it.
     */
    public function url(Model $subject): string;
}
