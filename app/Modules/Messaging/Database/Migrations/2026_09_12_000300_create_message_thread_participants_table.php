<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is in a conversation, and how much of it they have read.
 *
 * Membership is the authorisation rule: MessageThreadPolicy asks this table
 * and nothing else, so a thread is private by construction rather than by a
 * controller remembering to filter. Staff are the one exception and they are
 * NOT given rows here — a moderator reading a disputed order's thread is an
 * exception granted by the dispute, and writing them in as participants would
 * make that exception permanent and invisible.
 *
 * `last_read_at` rather than a per-message read table. A thread is read as a
 * whole — you open it and you have seen it — and the unread count is
 * therefore a comparison against the thread's last_message_at rather than a
 * join over every message. `unread_count` is not stored: it would have to be
 * incremented for every participant on every post, and the comparison is free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_thread_participants', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('message_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('role', 16)->comment('ThreadRole: buyer, seller or mechanic.');
            $table->timestamp('last_read_at')->nullable();

            $table->timestamps();

            $table->unique(['message_thread_id', 'user_id'], 'message_thread_participants_unique');

            /* "Everything unread for this person", the bell's second number. */
            $table->index(['user_id', 'last_read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_thread_participants');
    }
};
