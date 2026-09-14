<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One message in a thread.
 *
 * `body` is the text as it will be read — after the automatic screen. What
 * the sender originally typed is deliberately NOT kept, for the same reason
 * reviews do not keep it: the point of removing a phone number before payment
 * is that the platform stops holding it, and a table quietly retaining every
 * redacted number would undo that. `screen_flags` records what kind of thing
 * was taken out, which is what the recipient needs to understand the
 * "[removed]" in front of them.
 *
 * Redaction happens only while the thread's money is still in escrow — see
 * MessageThread::allowsContactDetails(). After payment a buyer and a seller
 * are entitled to each other's details, so the screen steps aside rather than
 * standing between two people arranging a collection. This is a
 * contact-visibility rule, not an anti-disintermediation one: nothing here
 * tries to stop people dealing off-platform once they have paid.
 *
 * A flagged message is DELIVERED. Ratings holds a flagged review for a
 * moderator because a review is publishing; holding a message would break the
 * conversation it belongs to, and the report button plus the dispute view are
 * the backstop instead.
 *
 * `user_id` is nullable for the messages the platform writes itself — "this
 * order was cancelled" — which belong in the thread but have no author.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('message_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->text('body')->comment('As delivered: redacted, never the raw text.');
            $table->json('screen_flags')->nullable();

            $table->timestamps();

            /* A thread, in order — the only way this table is ever read. */
            $table->index(['message_thread_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
