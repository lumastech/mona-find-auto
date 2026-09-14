<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What was bought, described as it was described at the time.
     *
     * The name, SKU and condition are copied rather than joined. A seller who
     * renames a listing, corrects a SKU or re-grades a part from Brand New to
     * Used must not thereby rewrite what a buyer's receipt says they bought —
     * and a listing that is deleted outright must not take the order history
     * with it, which is why the variant reference does not cascade.
     *
     * `quotation_id` rides along from the cart line so that a price nobody
     * can find on any listing can still be traced to the offer that set it.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quotation_id')->nullable()
                ->comment('Set when the price came from an accepted quote rather than the shelf.');

            /* The description, frozen. */
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('sku', 64)->nullable();
            $table->string('condition', 24)->nullable()
                ->comment('Catalog Condition as it stood: brand_new, used, car_breaker.');
            $table->string('inspection_status', 16)->nullable()
                ->comment('Whether MonaFind had inspected the listing when it was bought.');

            $table->unsignedBigInteger('unit_price_ngwee')->comment('VAT-inclusive, as shown to the buyer.');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('total_ngwee');

            $table->timestamps();

            $table->index('order_id');
            $table->index('product_variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
