<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A buyer's objection to an order, and what MonaFind did about it.
     *
     * One live dispute per order — enforced by a partial-free unique index on
     * (order_id, status) being deliberately absent and by the service
     * checking instead, because a buyer whose first dispute was resolved
     * against them must still be able to raise a second one if the
     * replacement part is also wrong.
     *
     * The resolution is not a note. It is the instruction Payments acts on
     * when it consumes DisputeResolved, which is why it is a closed enum with
     * an amount beside it rather than free text for a moderator to explain
     * themselves in. The explanation goes in resolution_note, where nothing
     * reads it but a person.
     *
     * Photos are Media Library attachments on the `evidence` collection: a
     * dispute about a cracked housing is decided by looking at the housing.
     */
    public function up(): void
    {
        Schema::create('order_disputes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opened_by')->constrained('users')->cascadeOnDelete();

            $table->string('reason', 32)->comment('DisputeReason.');
            $table->text('details');

            $table->string('status', 16)->default('open');

            $table->string('resolution', 24)->nullable()
                ->comment('DisputeResolution: release, partial_refund or full_refund.');
            $table->unsignedBigInteger('refund_amount_ngwee')->default(0);
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            /* The moderator queue: what is open, oldest first. */
            $table->index(['status', 'created_at']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_disputes');
    }
};
