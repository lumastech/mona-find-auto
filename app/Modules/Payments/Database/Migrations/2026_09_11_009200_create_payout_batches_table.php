<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A run of seller payouts, from "who is owed money" to "the money left".
     *
     * ## Dual control
     *
     * A batch is built by one person (or by the nightly schedule) and
     * released by another. `prepared_by` and `approved_by` are separate
     * columns and the service refuses to let one user fill both, because a
     * payout run is the single largest outbound money movement on the
     * platform and one compromised account should not be enough to empty it.
     *
     * ## Why the totals are stored
     *
     * `total_ngwee` is written when the batch is built, not summed from the
     * lines when it is read. Between building and approving, a seller's
     * payable can move — a refund posts, a clawback lands — and the figure
     * the approver signed off has to be the figure they actually saw.
     */
    public function up(): void
    {
        Schema::create('payout_batches', function (Blueprint $table): void {
            $table->id();

            $table->string('reference', 32)->unique()
                ->comment('PB-{date}-{n}. What Finance calls this run.');

            $table->string('status', 24)->default('draft')
                ->comment('PayoutBatchStatus: draft, awaiting_approval, approved, processing, completed, failed, cancelled.');

            $table->unsignedInteger('line_count')->default(0);
            $table->unsignedBigInteger('total_ngwee')->default(0)
                ->comment('The total as it stood when the batch was built and approved.');
            $table->unsignedBigInteger('paid_ngwee')->default(0);
            $table->unsignedBigInteger('failed_ngwee')->default(0);

            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Null when the nightly schedule built it.');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Never the same user as prepared_by. Dual control.');

            $table->text('approval_note')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_batches');
    }
};
