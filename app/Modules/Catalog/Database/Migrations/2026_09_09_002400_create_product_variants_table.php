<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What is actually bought: a SKU, a price and a quantity.
     *
     * Every product has at least one, even when the seller never thought
     * about variants — a single-variant product gets one created for it. That
     * way the cart, the order line and the ledger only ever deal with one
     * shape, instead of branching on whether a product happens to have
     * options.
     *
     * The price is an integer number of ngwee and is VAT-INCLUSIVE: the
     * seller is responsible for VAT on the goods, and MonaFind invoices only
     * its commission.
     */
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('sku', 64);
            $table->string('name')->nullable()
                ->comment('What tells this option apart, e.g. "Left hand". Null on a single-variant product.');

            $table->unsignedBigInteger('price')->comment('VAT-inclusive price in ngwee. Never a float.');
            $table->unsignedInteger('quantity')->default(0);

            $table->boolean('is_default')->default(false)
                ->comment('The option shown first, and the only one on a single-variant product.');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            /* A seller's own SKU may repeat across the platform, never within one listing. */
            $table->unique(['product_id', 'sku']);
            $table->index(['product_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
