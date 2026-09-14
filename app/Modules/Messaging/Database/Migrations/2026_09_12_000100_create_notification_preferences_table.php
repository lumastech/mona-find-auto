<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One person's answer to "send me this, by this" — and only where it differs
 * from the platform default.
 *
 * Absence means yes. A row is written only when somebody turns something off
 * (or turns it back on after turning it off), so a hundred thousand accounts
 * who never open the preference screen cost nothing, and adding an event to
 * the catalogue does not require backfilling a row per user per channel.
 *
 * The columns are strings rather than foreign keys because the things they
 * name are enum cases in code, not rows. The router ignores a value it does
 * not recognise, so retiring an event leaves harmless rows behind rather than
 * a broken screen.
 *
 * Mandatory events (verification codes, suspensions) are never consulted
 * here. A row saying otherwise is allowed to exist — the screen does not
 * write one — and the router simply does not read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event', 64)->comment('NotificationEvent value.');
            $table->string('channel', 16)->comment('NotificationChannel value.');
            $table->boolean('enabled')->default(true);

            $table->timestamps();

            /* One answer per person per event per channel. */
            $table->unique(['user_id', 'event', 'channel'], 'notification_preferences_unique');

            /* The router's only query: everything this person has decided. */
            $table->index(['user_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
