<?php

declare(strict_types=1);

use App\Support\Database\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every move an order ever made, and who made it.
     *
     * Append-only in both layers, like the stock ledger and for the same
     * reason: this table is the evidence in a dispute. When a buyer says the
     * part was never ready and the seller says it sat on the counter for a
     * week, what settles it is a row nobody could have gone back and tidied.
     *
     * The actor is recorded as a type as well as a user id, because a large
     * share of these rows have no person behind them — a payment webhook, a
     * confirmation window closing — and "the platform did this on a timer" is
     * a materially different claim from "somebody clicked a button".
     */
    public function up(): void
    {
        Schema::create('order_status_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 32)->nullable()->comment('Null on the row that created the order.');
            $table->string('to_status', 32);

            $table->string('actor_type', 16)->comment('OrderActorType: buyer, seller, staff or system.');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Null for anything the platform did on its own.');

            $table->text('reason')->nullable();
            $table->json('context')->nullable()->comment('Anything worth keeping about this particular move.');

            $table->timestamp('created_at');

            $table->index(['order_id', 'id']);
            $table->index(['to_status', 'created_at']);
        });

        AppendOnlyTable::protect('order_status_events');
    }

    public function down(): void
    {
        AppendOnlyTable::unprotect('order_status_events');

        Schema::dropIfExists('order_status_events');
    }
};
