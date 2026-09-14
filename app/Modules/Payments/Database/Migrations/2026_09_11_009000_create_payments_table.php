<?php

declare(strict_types=1);

use App\Support\Database\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every state a collection attempt was ever observed in.
     *
     * ## Why this is a log and not a row per payment
     *
     * CLAUDE.md makes Payment append-only, and that rules out the obvious
     * design of one mutable row per attempt marched from pending to
     * successful. What it buys is worth more than the convenience it costs:
     * the record of what the gateway said, and when, can never be quietly
     * rewritten afterwards — which is the entire point of keeping it.
     *
     * So a row here is one OBSERVATION of one attempt. Starting a payment
     * writes a pending row; the verify call or the webhook writes a
     * successful or failed one. The attempt's current state is its latest
     * row, which `Payment::currentFor()` reads.
     *
     * ## The index that makes webhooks idempotent
     *
     * `(reference, status)` is unique, and that single line is what makes a
     * webhook delivered three times post once. The third insert loses to the
     * unique index inside the database rather than to a select-then-insert
     * that two queue workers can both pass — the same reasoning as
     * `journal_entries.idempotency_key`, and for the same reason: money.
     *
     * A consequence worth stating, because it is a feature: an attempt can
     * never go successful → failed → successful. Once a terminal row exists
     * for a reference, a contradicting one is refused, so a late `failed`
     * webhook after a confirmed success cannot unpay an order.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();

            /*
             * The group is what the buyer pays; orders inside it are settled
             * together or not at all. Nullable because a refund transfer is
             * also recorded here and belongs to an order, not to a checkout.
             */
            $table->foreignId('order_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 64)
                ->comment('MFA-{orderGroupPublicId}-{attempt}. What Lenco knows this attempt by.');
            $table->unsignedTinyInteger('attempt')->default(1);

            $table->string('status', 24)->comment('PaymentStatus: pending, successful, failed, reversed.');
            $table->string('channel', 24)->nullable()->comment('PaymentChannel: card, mobile_money, unknown.');
            $table->string('bearer', 16)->nullable()->comment('Who paid Lenco fee: merchant or customer.');

            /*
             * Three different amounts and they are genuinely different. Gross
             * is what the buyer was charged; fee is Lenco's cut; settled is
             * what actually reached MonaFind's bank days later. Reconciliation
             * is largely the business of noticing when gross - fee != settled.
             */
            $table->unsignedBigInteger('amount_ngwee')->comment('Gross, what the buyer paid.');
            $table->unsignedBigInteger('fee_ngwee')->nullable()->comment("Lenco's charge on the collection.");
            $table->unsignedBigInteger('settled_ngwee')->nullable()->comment('What Lenco settled to us.');

            $table->string('lenco_id', 64)->nullable()->comment('Lenco collection id.');
            $table->string('lenco_reference', 64)->nullable()->comment("Lenco's own reference for it.");

            $table->text('failure_reason')->nullable();

            /*
             * How we came to believe this. A status we read back ourselves and
             * a status a webhook asserted are not equally trustworthy, and
             * when they disagree the first question is always which was which.
             */
            $table->string('source', 24)->default('verify')
                ->comment('PaymentSource: verify, webhook, poll, initiate.');

            $table->json('raw')->nullable()->comment('The gateway payload exactly as it arrived.');

            $table->timestamp('observed_at');
            $table->timestamp('created_at')->nullable();

            /*
             * The idempotency guarantee. See the class docblock: this is what
             * makes a duplicate webhook a no-op at the database rather than a
             * race two queue workers can both win.
             */
            $table->unique(['reference', 'status']);

            $table->index(['order_group_id', 'observed_at']);
            $table->index(['status', 'observed_at']);
            $table->index('lenco_id');
        });

        AppendOnlyTable::protect('payments');
    }

    public function down(): void
    {
        AppendOnlyTable::unprotect('payments');

        Schema::dropIfExists('payments');
    }
};
