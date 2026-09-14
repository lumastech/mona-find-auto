<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money going back to a buyer, and how it is getting there.
     *
     * ## Two very different mechanics behind one word
     *
     * A mobile-money or bank refund is a Lenco transfer: MonaFind sends money
     * to the buyer's wallet and it arrives. A CARD refund is not — Lenco
     * handles card reversals out of band, so those cannot be executed by an
     * API call at all. They are created `manual` and land in a Finance task
     * queue, because the alternative is code that pretends to have refunded a
     * card and a buyer who is still waiting a fortnight later.
     *
     * ## The ledger is posted at approval, not at arrival
     *
     * A refund's ledger entry is written when the refund is decided, not when
     * the transfer lands, because the decision is what changes what the
     * platform owes. `journal_entry_id` therefore fills in early and stays;
     * `status` tracks the money's own journey separately.
     */
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->comment('The buyer being refunded.');
            $table->foreignId('order_dispute_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 64)->unique()->comment('RF-{order}-{n}. The transfer reference at Lenco.');

            $table->string('status', 24)->default('pending')
                ->comment('RefundStatus: pending, manual, sent, completed, failed, cancelled.');
            $table->string('method', 24)
                ->comment('RefundMethod: mobile_money, bank, card_manual, goodwill.');
            $table->string('reason_code', 32)->nullable()
                ->comment('RefundReason: dispute, auto_cancel, seller_cancelled, admin.');

            $table->unsignedBigInteger('amount_ngwee');

            /*
             * Where it is going, copied from the original payment rather than
             * from the buyer's profile. A refund follows the money back to the
             * wallet it came from; a buyer who changed their phone number last
             * week must not be able to redirect an old payment.
             */
            $table->string('destination_phone', 32)->nullable();
            $table->string('destination_network', 16)->nullable();
            $table->string('destination_account', 64)->nullable();
            $table->string('destination_bank_code', 16)->nullable();

            $table->string('lenco_transfer_id', 64)->nullable();
            $table->text('failure_reason')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Null when a scheduled auto-cancel raised it.');
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete()
                ->comment('The Finance user who cleared a manual card refund.');

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'created_at']);
            $table->index(['status', 'created_at']);

            /* The Finance task queue: manual refunds nobody has cleared yet. */
            $table->index(['method', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
