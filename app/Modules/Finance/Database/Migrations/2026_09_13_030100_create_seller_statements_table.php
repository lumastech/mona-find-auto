<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A seller's month, closed off.
     *
     * Every figure is copied from the ledger at generation time and never
     * recomputed. That is the same decision the commission invoice makes and
     * for the same reason: a statement a seller downloaded in October and one
     * they download again in March have to be the same document, and a
     * statement that re-queries live data is one backdated adjustment away
     * from silently disagreeing with the copy in their files.
     *
     * Unique on (seller, year, month), so the monthly job is safe to re-run —
     * a month already closed is left exactly as it was rather than being
     * rebuilt against a ledger that has moved on.
     *
     * The closing figures are POSITIONS at the last instant of the month, not
     * movements within it. A seller's closing payable includes money earned
     * in August and not yet paid, which is precisely what they want to see.
     */
    public function up(): void
    {
        Schema::create('seller_statements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->date('period_start');
            $table->date('period_end');

            $table->unsignedInteger('order_count')->default(0);
            $table->unsignedBigInteger('sales_ngwee')->default(0)
                ->comment('Gross value of this seller\'s orders that took payment in the month.');

            /* MonaFind's take, split by the monetisation component that earned it. */
            $table->unsignedBigInteger('commission_ngwee')->default(0);
            $table->unsignedBigInteger('addon_fee_ngwee')->default(0);
            $table->unsignedBigInteger('referral_fee_ngwee')->default(0);
            $table->unsignedBigInteger('vat_on_commission_ngwee')->default(0);

            $table->unsignedBigInteger('refunds_ngwee')->default(0)
                ->comment('Money returned to buyers out of this seller\'s orders.');
            $table->unsignedBigInteger('payouts_ngwee')->default(0)
                ->comment('Paid out to the seller in the month, on confirmed transfers only.');

            $table->unsignedBigInteger('reserve_withheld_ngwee')->default(0);
            $table->unsignedBigInteger('reserve_released_ngwee')->default(0);

            /* Positions at the last instant of the month, not movements within it. */
            $table->bigInteger('closing_payable_ngwee')->default(0)
                ->comment('Signed: negative means the seller owes the platform after a clawback.');
            $table->bigInteger('closing_reserve_ngwee')->default(0);

            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['seller_id', 'period_year', 'period_month'], 'seller_statements_period_unique');
            $table->index(['seller_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_statements');
    }
};
