<?php

declare(strict_types=1);

use App\Support\Database\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The stock ledger: every change to every shelf, and why.
     *
     * Append-only, for the same reason the financial ledger is. A seller who
     * counts two of something the platform says it has three of needs a
     * history that cannot have been quietly tidied up, and an oversell
     * dispute is decided by what this table says happened and in what order.
     *
     * `quantity_after` is stored rather than derived. It is written inside
     * the same locked transaction that wrote the quantity onto the variant,
     * so the two can be reconciled against each other; a sum over the ledger
     * would only ever agree with itself.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();

            $table->integer('quantity_change')->comment('Signed: negative for a sale, positive for a restock.');
            $table->unsignedInteger('quantity_before');
            $table->unsignedInteger('quantity_after');

            $table->string('reason', 32);
            $table->string('reference', 64)->nullable()
                ->comment('Order or payment reference for an order-driven movement, e.g. "MFA-1042-1".');
            $table->unsignedBigInteger('order_id')->nullable()
                ->comment('Set by the Orders module. Not a foreign key: stock history outlives an order row.');
            $table->text('note')->nullable();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Null when the platform moved the stock on its own.');

            $table->timestamp('created_at');

            /* A shelf's history, newest first. */
            $table->index(['product_variant_id', 'id']);
            $table->index(['seller_id', 'created_at']);

            /*
             * One movement per order, per variant, per reason. This is what
             * makes an order-paid webhook arriving twice harmless: the second
             * insert collides instead of taking the stock down again.
             */
            $table->unique(['order_id', 'product_variant_id', 'reason'], 'stock_movements_order_variant_reason_unique');
        });

        AppendOnlyTable::protect('stock_movements');
    }

    public function down(): void
    {
        AppendOnlyTable::unprotect('stock_movements');

        Schema::dropIfExists('stock_movements');
    }
};
