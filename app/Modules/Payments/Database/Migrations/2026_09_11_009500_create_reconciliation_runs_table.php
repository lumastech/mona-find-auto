<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One night's answer to "does what Lenco thinks happened match our books".
     *
     * A run is kept even when it finds nothing, because "reconciliation ran
     * and was clean" and "reconciliation did not run" look identical if only
     * exceptions are stored — and they could hardly be more different.
     *
     * Unique on the date so a re-run replaces rather than duplicates: two runs
     * for the same day would double-count every exception on the dashboard.
     */
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table): void {
            $table->id();

            $table->date('for_date')->unique();
            $table->string('status', 24)->default('running')
                ->comment('ReconciliationStatus: running, clean, exceptions, failed.');

            $table->unsignedInteger('collections_checked')->default(0);
            $table->unsignedInteger('settlements_checked')->default(0);
            $table->unsignedInteger('transactions_checked')->default(0);
            $table->unsignedInteger('exception_count')->default(0);

            $table->unsignedBigInteger('gateway_total_ngwee')->default(0)
                ->comment('What Lenco says it collected that day.');
            $table->unsignedBigInteger('ledger_total_ngwee')->default(0)
                ->comment('What platform_cash moved by, per our own books.');
            $table->bigInteger('variance_ngwee')->default(0)
                ->comment('Signed. The headline number Finance actually looks at.');

            $table->text('failure_reason')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'for_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_runs');
    }
};
