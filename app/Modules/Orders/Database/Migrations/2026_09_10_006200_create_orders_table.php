<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One seller's part of a purchase, from payment to settlement.
     *
     * Nearly half the columns here are snapshots, and that is the point of
     * the table. A seller's commission, their payment mode and the platform's
     * escrow windows are all things staff change; an order that read them
     * live would have its terms rewritten underneath it by an administrator
     * adjusting a default weeks later. So everything that decides who gets
     * what is copied onto the row at payment time and never read from its
     * source again — the same discipline that makes the policy versions on
     * terms_acceptances worth recording.
     *
     * The delivery address is snapshotted for the same reason and one more: a
     * buyer editing their address book must not silently redirect a parcel
     * that has already been dispatched.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 24)->unique()
                ->comment('The reference a buyer reads out in a shop, e.g. MF-7QK4ZP2A.');

            $table->foreignId('order_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()
                ->comment('The buyer, denormalised off the group so a buyer\'s order list is one query.');
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();

            $table->string('status', 32)->default('pending_payment');

            /* How this shop's part reaches the buyer, and what it costs. */
            $table->string('fulfilment_method', 16)->comment('FulfilmentMethod: pickup or delivery.');
            $table->foreignId('user_address_id')->nullable()->constrained('user_addresses')->nullOnDelete()
                ->comment('Which address book entry was chosen. The snapshot below is what governs.');
            $table->json('delivery_address')->nullable()
                ->comment('The address as it stood when the order was placed. Never refreshed.');
            $table->text('delivery_instructions')->nullable();

            $table->unsignedBigInteger('items_total_ngwee');
            $table->unsignedBigInteger('delivery_fee_ngwee')->default(0);
            $table->unsignedBigInteger('total_ngwee');

            /*
             * Snapshotted at payment time. Everything below this line is a
             * copy of something that lives elsewhere and is allowed to change
             * there. Payments (Prompt 09) fills them in when the collection
             * settles; they are null on an unpaid order because an order that
             * never gets paid never acquires terms.
             */
            $table->string('payment_mode', 16)->nullable()
                ->comment('PaymentMode as it stood at payment: escrow or direct.');
            $table->json('monetisation_snapshot')->nullable()
                ->comment('Commission, add-on and referral terms in force when the money arrived.');
            $table->unsignedSmallInteger('auto_complete_window_days')->nullable()
                ->comment('The escrow window this order runs on, copied from settings at payment time.');
            $table->unsignedSmallInteger('seller_confirm_window_hours')->nullable();
            $table->timestamp('snapshot_at')->nullable();

            /*
             * The lifecycle, written down. Each column is set once by the
             * state machine as the order passes through; the full narrative
             * with actors and reasons is in order_status_events. These exist
             * so that "orders awaiting confirmation for more than 24 hours"
             * is an index scan rather than a walk over an event log.
             */
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('handed_over_at')->nullable()
                ->comment('Collected or delivered — the moment the buyer\'s checking window starts.');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            /*
             * The two deadlines the scheduled jobs run off. Stored rather
             * than computed so that a setting changed today cannot move a
             * deadline an order is already running against, and so that the
             * sweeps are a simple `where ... <= now` on an index.
             */
            $table->timestamp('confirm_due_at')->nullable()
                ->comment('Auto-cancel after this if the seller has still not confirmed.');
            $table->timestamp('auto_complete_at')->nullable()
                ->comment('Auto-complete after this unless the buyer confirms or disputes first.');

            $table->text('cancellation_reason')->nullable();
            $table->unsignedBigInteger('refunded_amount_ngwee')->default(0)
                ->comment('Written by dispute resolution; the ledger entries themselves live in Payments.');

            $table->timestamps();

            /* A buyer's order list, and a seller's inbox. */
            $table->index(['user_id', 'created_at']);
            $table->index(['seller_id', 'status', 'created_at']);
            $table->index(['status', 'confirm_due_at']);
            $table->index(['status', 'auto_complete_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
