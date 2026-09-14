<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A conversation, hung off the thing it is about.
 *
 * The subject is polymorphic because a conversation on MonaFind is always
 * about something concrete — a listing, a request for a quote, an order — and
 * a thread floating free of all three is a thread nobody can act on. It is
 * also what decides the rules: whether contact details survive the screen,
 * who the participants are, and whether staff may read it.
 *
 * `dedupe_key` is what stops a buyer pressing "Contact seller" four times and
 * getting four conversations. It is a hash of the subject and the sorted
 * participant ids, which is the only form that works across morphs — a unique
 * index on (subject_type, subject_id, buyer_id) would need a buyer column
 * that means nothing on a thread between a buyer and a mechanic. Computed in
 * MessageThread::dedupeKeyFor(), and unique here so that two simultaneous
 * presses cannot both win.
 *
 * `seller_id` is denormalised for the seller inbox, which is the one query
 * this table is asked for constantly: every thread for a shop, newest first.
 * Reaching it through the participants table would be a join per page load
 * for a number that never changes. Cart items carry a seller id for the same
 * reason.
 *
 * `last_message_at` is likewise written rather than derived. An inbox is
 * sorted by it and a subquery per row is what makes an inbox slow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_threads', function (Blueprint $table): void {
            $table->id();

            $table->morphs('subject');
            $table->string('dedupe_key', 64)->unique();

            /* The shop this conversation concerns, when there is one. */
            $table->foreignId('seller_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('subject_label')->comment('What the thread is about, as it read when it opened.');

            $table->unsignedInteger('messages_count')->default(0);
            $table->timestamp('last_message_at')->nullable();

            /*
             * A closed thread is read-only. Orders close theirs when the
             * order closes, so a conversation cannot be reopened years later
             * against a shop that has since left.
             */
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            /* The seller inbox, and the storefront's "my conversations". */
            $table->index(['seller_id', 'last_message_at']);

            /* Everything about one listing / quote / order. */
            $table->index(['subject_type', 'subject_id', 'last_message_at'], 'message_threads_subject_recent_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_threads');
    }
};
