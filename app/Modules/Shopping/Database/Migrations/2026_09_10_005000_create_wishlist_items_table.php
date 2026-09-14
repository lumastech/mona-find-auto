<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Save this for later."
     *
     * Kept per listing rather than per variant: a buyer saving a headlamp is
     * saving the part, and the choice between left and right is one they make
     * when they buy it. The cart is where variants matter.
     *
     * The two snapshot columns are the whole point of the row. A wishlist
     * that only says what a buyer liked is a bookmark; one that can say "this
     * is K200 cheaper than when you saved it" or "this sold out" is a reason
     * to come back, and neither sentence can be written without knowing what
     * was true at the time. They are written once, when the item is saved,
     * and never refreshed — a moving baseline would report no change at all.
     */
    public function up(): void
    {
        Schema::create('wishlist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('price_ngwee_at_save')->nullable()
                ->comment('The cheapest variant price when this was saved. Never updated — it is the baseline a price drop is measured against.');
            $table->boolean('in_stock_at_save')->default(true)
                ->comment('Whether any variant had stock when this was saved.');

            $table->timestamps();

            /* One row per buyer per listing; saving twice is not two saves. */
            $table->unique(['user_id', 'product_id']);

            /* The wishlist page: this buyer's saves, newest first. */
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
    }
};
