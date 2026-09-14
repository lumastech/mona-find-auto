<?php

declare(strict_types=1);

use App\Modules\Shopping\Enums\QuotationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A request for quotation: "what would ten of these cost me?"
     *
     * This is how a great deal of Zambian parts trade actually happens. The
     * shelf price is for one; a garage buying ten expects a conversation, and
     * before MonaFind that conversation happened on WhatsApp where neither
     * side could prove afterwards what had been agreed. Keeping it as a row
     * is what lets the quoted price follow the buyer into the cart, into the
     * order line, and into the ledger.
     *
     * The seller's answer is three columns, and all three matter. A price
     * without a validity date is an offer with no end, which is not something
     * a shop can honour; a price without a delivery note leaves the buyer to
     * guess whether ten alternators arrive tomorrow or next month.
     */
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()
                ->comment('The buyer who asked.');
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete()
                ->comment('The shop being asked. Only this shop may answer.');
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();

            $table->string('status')->default(QuotationStatus::Open->value);
            $table->unsignedInteger('quantity');
            $table->text('message')->nullable()
                ->comment('What the buyer asked for in their own words.');

            /* The seller's answer. Null until they give one. */
            $table->unsignedBigInteger('quoted_unit_price_ngwee')->nullable()
                ->comment('VAT-inclusive price per unit, in ngwee. Wins over the listing price once accepted.');
            $table->date('valid_until')->nullable()
                ->comment('The last day the quoted price stands. After this the quote expires on its own.');
            $table->text('delivery_note')->nullable();
            $table->text('decline_reason')->nullable();

            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            /* The seller's inbox: what this shop has been asked, oldest first. */
            $table->index(['seller_id', 'status', 'created_at']);

            /* The buyer's list of their own requests. */
            $table->index(['user_id', 'status']);

            /* The expiry sweep: quoted, and past their date. */
            $table->index(['status', 'valid_until']);
        });

        /*
         * The other half of cart_items.quotation_id.
         *
         * The column is declared in the cart_items migration, which runs
         * BEFORE this one and therefore cannot constrain a table that does
         * not exist. The constraint is added here, where quotations does.
         */
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->foreign('quotation_id')->references('id')->on('quotations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        /* Dropped first: MySQL will not drop a table another still references. */
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropForeign(['quotation_id']);
        });

        Schema::dropIfExists('quotations');
    }
};
