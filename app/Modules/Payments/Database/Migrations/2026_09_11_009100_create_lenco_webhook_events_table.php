<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every webhook Lenco has ever sent us, verified and stored before it is
     * acted on.
     *
     * The endpoint's job is deliberately tiny: check the signature, write the
     * row, queue the work, answer 200. Lenco retries anything that is not a
     * 200, so a slow or throwing handler turns one event into a storm — and
     * an event processed inside the request is an event lost when the process
     * dies halfway.
     *
     * `lenco_event_id` is unique, so the row is the idempotency record: a
     * redelivery loses the insert and is acknowledged without being queued a
     * second time. Lenco does not always supply an id, so the fingerprint of
     * the body stands in — the same body twice is the same event twice.
     *
     * Not append-only, unlike payments: the row carries processing state
     * (processed_at, attempts, last_error) which by definition changes after
     * it is written. What must never be edited is the CONCLUSION, and that
     * lives in payments, which is.
     */
    public function up(): void
    {
        Schema::create('lenco_webhook_events', function (Blueprint $table): void {
            $table->id();

            $table->string('lenco_event_id', 128)->unique()
                ->comment("Lenco's event id, or a hash of the body when it sends none.");

            $table->string('event', 64)->comment('collection.successful, transfer.failed, and so on.');
            $table->string('reference', 64)->nullable()->comment('Our reference, pulled out for lookups.');
            $table->string('lenco_id', 64)->nullable();

            $table->json('payload')->comment('The body exactly as received, signature-verified.');

            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->index(['event', 'received_at']);
            $table->index('reference');

            /* The queue for "arrived but never got dealt with", which is the thing worth alerting on. */
            $table->index(['processed_at', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lenco_webhook_events');
    }
};
