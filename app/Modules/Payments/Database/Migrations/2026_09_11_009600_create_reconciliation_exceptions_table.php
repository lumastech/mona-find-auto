<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One thing that did not add up, and whether anyone has dealt with it.
     *
     * Three kinds, and they mean genuinely different things:
     *
     * - `missing` — Lenco took money we have no Payment row for. The worst
     *   kind: a buyer has paid and the platform does not know.
     * - `amount_mismatch` — both sides have it, for different amounts.
     * - `orphan` — we have a successful Payment that Lenco has no record of.
     *   Usually a test fixture or a hand-written row; occasionally something
     *   much worse.
     *
     * Resolution is a note by a named person, never a delete: an exception
     * that can be made to disappear is an exception nobody has to explain.
     */
    public function up(): void
    {
        Schema::create('reconciliation_exceptions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('reconciliation_run_id')->constrained()->cascadeOnDelete();

            $table->string('type', 32)
                ->comment('ReconciliationExceptionType: missing, amount_mismatch, orphan, unsettled, ledger_variance.');
            $table->string('severity', 16)->default('warning');

            $table->string('reference', 64)->nullable();
            $table->string('lenco_id', 64)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_group_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedBigInteger('gateway_amount_ngwee')->nullable();
            $table->unsignedBigInteger('ledger_amount_ngwee')->nullable();
            $table->bigInteger('variance_ngwee')->nullable();

            $table->text('detail');
            $table->json('context')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();

            $table->timestamps();

            $table->index(['reconciliation_run_id', 'type']);
            $table->index(['resolved_at', 'severity']);
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_exceptions');
    }
};
