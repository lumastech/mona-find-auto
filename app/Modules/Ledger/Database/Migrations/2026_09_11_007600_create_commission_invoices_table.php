<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MonaFind's invoice to a seller, for its commission and nothing else.
     *
     * The distinction the brief insists on and the reason this table is
     * narrow: product prices are VAT-inclusive and the seller answers for
     * their own output tax on the goods. MonaFind invoices its commission
     * plus VAT on that commission. It never invoices the order.
     *
     * One per order, enforced by a unique index rather than by whoever calls
     * the service remembering — the entry that recognises the revenue is
     * idempotent, and the invoice beside it has to be too.
     *
     * Every figure is copied from the order's snapshot at the moment revenue
     * was recognised. Nothing here is recomputed later, which is what makes
     * an invoice reprintable years afterwards and identical each time.
     */
    public function up(): void
    {
        Schema::create('commission_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique()->comment('MFA-INV-{year}-{sequence}. Gapless within a year.');
            $table->unsignedSmallInteger('series_year');
            $table->unsignedInteger('series_number');

            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete()
                ->comment('The entry that recognised this revenue.');

            $table->unsignedBigInteger('goods_ngwee')->comment('What commission was charged against.');
            $table->unsignedBigInteger('commission_ngwee');
            $table->unsignedBigInteger('addon_fee_ngwee')->default(0);
            $table->unsignedBigInteger('referral_fee_ngwee')->default(0);
            $table->unsignedBigInteger('vat_ngwee')->default(0);
            $table->unsignedBigInteger('total_ngwee')->comment('Commission plus its VAT — what the seller is invoiced.');

            $table->string('vat_rate_percent', 8)->comment('The rate in force when the revenue was recognised.');
            $table->json('monetisation_snapshot')->comment('The terms the order settled under, copied whole.');

            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique(['series_year', 'series_number']);
            $table->index(['seller_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_invoices');
    }
};
