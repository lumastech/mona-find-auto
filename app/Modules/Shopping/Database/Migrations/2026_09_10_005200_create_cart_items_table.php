<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One line: a variant, a quantity, and the price it was added at.
     *
     * `seller_id` is denormalised onto the line deliberately. A MonaFind cart
     * is nearly always multi-seller — a buyer fixing one car buys the filter
     * from one shop and the mirror from a breaker — and the cart is grouped
     * by shop everywhere it is shown, priced per shop, and eventually paid
     * for as one order group of several orders. Grouping through two joins on
     * every read, to arrive at a value that cannot change for the life of the
     * line, buys nothing.
     *
     * `unit_price_ngwee` is what the buyer was last shown, not what the
     * listing says now. Keeping it is what lets the cart tell them the price
     * moved instead of quietly charging the new one.
     *
     * `quotation_id` is the exception to that: on a line that came from an
     * accepted quote, the quoted price governs, and it survives into the
     * order line so that the money paid can be traced back to the offer that
     * was made.
     */
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_ngwee')
                ->comment('VAT-inclusive, in ngwee. What the buyer was last shown — compared against the listing to spot a price change.');

            /*
             * An accepted quote's line. Nullable because most lines are not
             * negotiated; set, it makes the price a promise rather than a
             * snapshot, and is carried through to the order line.
             *
             * The COLUMN is declared here and the FOREIGN KEY is added by the
             * quotations migration, which runs next. MySQL refuses a
             * constraint against a table that does not exist yet, so
             * `constrained()` here made `migrate:fresh` fail on MySQL while
             * passing on SQLite — which does not enforce the reference at
             * create time. Two engines, two answers, and the one that matters
             * in production said no.
             */
            $table->unsignedBigInteger('quotation_id')->nullable();

            $table->timestamps();

            /*
             * One line per option. Adding the same option again adds to the
             * quantity — except on a quoted line, which is its own line at
             * its own price, hence the quotation in the key.
             *
             * Mind what this index does and does not enforce: SQL treats NULLs
             * as distinct, so it stops a buyer holding the same quote twice
             * but not the same un-quoted option twice. CartService::add() is
             * what keeps that half true, and the index is the backstop for
             * the half a service call could otherwise duplicate.
             */
            $table->unique(['cart_id', 'product_variant_id', 'quotation_id']);

            /* Reading a cart: every line, grouped by shop. */
            $table->index(['cart_id', 'seller_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
