<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every move a seller's application makes through the workflow.
     *
     * The audit trail records these too, but that table is platform-wide and
     * append-only; this one is the reviewer's working history for one file,
     * with the checklist they ticked, and it is what the admin detail screen
     * and the seller's own status card read.
     */
    public function up(): void
    {
        Schema::create('seller_verification_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 32)->nullable()->comment('Null for the first event.');
            $table->string('to_status', 32);
            $table->text('note')->nullable()->comment('The reviewer checklist notes.');
            $table->text('reason')->nullable()->comment('Required on a rejection or a suspension.');
            $table->json('checklist')->nullable()->comment('Which checks the reviewer ticked.');

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Null when the seller themselves moved it, e.g. by submitting.');

            $table->timestamp('created_at');

            $table->index(['seller_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_verification_events');
    }
};
