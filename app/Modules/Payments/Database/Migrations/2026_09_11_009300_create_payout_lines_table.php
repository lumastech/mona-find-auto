<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One seller's share of a payout batch.
     *
     * Lines fail independently and that is the whole design. A batch of forty
     * transfers where the eleventh bounces must pay the other thirty-nine —
     * so each line carries its own reference, its own gateway ids, its own
     * status and its own ledger entry, and a failure reverts that seller's
     * payable and nobody else's.
     *
     * ## The re-resolve check
     *
     * `resolved_name_at_payout` records what the gateway said the destination
     * account was called at the moment of paying, checked against the name
     * stored when the seller added it. A mismatch blocks the line rather than
     * paying it: an account number that has quietly changed hands is the
     * cheapest possible thing to catch here and the most expensive to unpick
     * afterwards.
     *
     * ## unresolved is not failed
     *
     * A transfer that timed out may have gone through. Those lines land in
     * `unresolved`, which does NOT revert the payable — only `failed` does.
     * Reverting a payable for money that actually left would pay the seller
     * twice on the next run.
     */
    public function up(): void
    {
        Schema::create('payout_lines', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('payout_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payout_account_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 64)->unique()
                ->comment('PO-{batch}-{seller}. The transfer reference at Lenco.');

            $table->string('status', 24)->default('pending')
                ->comment('PayoutLineStatus: pending, blocked, sent, paid, failed, unresolved.');

            $table->unsignedBigInteger('amount_ngwee');
            $table->unsignedBigInteger('fee_ngwee')->nullable()->comment("Lenco's charge, borne by the platform.");

            $table->string('method', 16)->nullable()->comment('PayoutMethod: bank or mobile_money.');
            $table->string('lenco_transfer_id', 64)->nullable();
            $table->string('lenco_recipient_id', 64)->nullable();

            $table->string('resolved_name_at_payout')->nullable()
                ->comment('The gateway name at payout time, compared against the stored one.');
            $table->text('block_reason')->nullable();
            $table->text('failure_reason')->nullable();

            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete()
                ->comment('Posted only once the transfer is confirmed, never on send.');

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            $table->index(['payout_batch_id', 'status']);
            $table->index(['seller_id', 'created_at']);
            $table->index('lenco_transfer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_lines');
    }
};
