<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A correction a human wrote, under dual control.
     *
     * Every other entry in the ledger is the consequence of something that
     * happened — a buyer paid, a window closed, a moderator decided. This is
     * the one place a person moves money because they say so, which is why it
     * is the one place that needs two of them: finance drafts, an
     * administrator approves, and only the approval posts. A pending
     * adjustment has moved nothing.
     *
     * The draft lines live in JSON rather than as journal lines, because a
     * journal line that exists but has not moved any money is exactly the
     * ambiguity the ledger is built to avoid. Nothing reaches
     * `journal_lines` until it is real.
     *
     * The row itself is not append-only: it carries a decision that has not
     * been made yet. What it produces is.
     */
    public function up(): void
    {
        Schema::create('ledger_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 16)->default('pending');
            $table->string('description');
            $table->text('reason')->comment('Mandatory. An adjustment without a stated reason is indistinguishable from a mistake.');

            $table->json('lines')
                ->comment('[{"account": "seller_payable", "direction": "debit", "amount_ngwee": 1000, "subject_type": "...", "subject_id": 4}]');
            $table->unsignedBigInteger('total_ngwee')->comment('The debit total of the draft, which equals its credit total.');

            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete()
                ->comment('Set on approval. Null while pending and forever if rejected.');

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_adjustments');
    }
};
