<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The VAT rate MonaFind charges on its commission, dated.
     *
     * A schedule rather than a setting, because a tax rate is a fact with a
     * commencement date. When ZRA moves the rate the platform must go on
     * answering "what was it on the 14th" for as long as anybody can query an
     * invoice — and the honest answer is not "whatever the settings row says
     * today".
     *
     * `effective_from` is unique, so a day has exactly one rate. The rate in
     * force at a moment is the latest row on or before it; the earliest row
     * is the floor, which is why the seeder plants one dated far enough back
     * to cover every order the platform has ever taken.
     *
     * Rows are never edited or deleted once their date has passed. A rate
     * that was charged was charged, and the orders that carry it in their
     * snapshot are the proof — changing the schedule cannot and must not
     * reach them.
     */
    public function up(): void
    {
        Schema::create('vat_rates', function (Blueprint $table): void {
            $table->id();

            $table->string('rate_percent', 8)
                ->comment('Decimal string, e.g. "16.00". Parsed exactly by Money::percentage().');

            $table->date('effective_from')->unique()
                ->comment('The first day this rate applies. One rate per day, enforced here.');

            $table->string('note')->nullable()->comment('The statutory instrument or the reason.');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_rates');
    }
};
