<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Tell me when this is back."
     *
     * Kept per variant rather than per listing: somebody waiting on the
     * left-hand mirror does not want to hear that the right-hand one arrived.
     *
     * A subscription is consumed rather than deleted when it fires, so the
     * row is the proof that the buyer was told once and will not be told
     * again. The unique index is on the pair alone, so re-subscribing after a
     * notification updates the same row rather than accumulating rows the
     * next restock would all fire at once.
     */
    public function up(): void
    {
        Schema::create('back_in_stock_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamp('notified_at')->nullable()
                ->comment('Set when the buyer was told. A row with this set is spent.');
            $table->timestamps();

            $table->unique(['product_variant_id', 'user_id']);

            /* The restock sweep: who is still waiting on this shelf. */
            $table->index(['product_variant_id', 'notified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('back_in_stock_subscriptions');
    }
};
