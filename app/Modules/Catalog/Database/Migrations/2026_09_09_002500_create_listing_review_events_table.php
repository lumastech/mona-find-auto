<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The moderation history of one listing.
     *
     * The audit log records that a decision was made; this records what the
     * seller was told, field by field, so a re-submission can be checked
     * against the reasons it was turned down for. Sellers read their own
     * rows, which is why the reasons here are written to be read by them.
     */
    public function up(): void
    {
        Schema::create('listing_review_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 20);
            $table->string('to_status', 20);

            $table->text('reason')->nullable()->comment('The summary shown to the seller.');
            $table->json('field_reasons')->nullable()
                ->comment('Per-field rejection reasons keyed by form field, e.g. {"photos": "Too dark to see the part."}.');
            $table->text('note')->nullable()->comment('Internal moderator note. Never shown to the seller.');

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Null when the platform did it, e.g. auto-unpublish on seller suspension.');
            $table->timestamp('created_at')->nullable();

            $table->index(['product_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_review_events');
    }
};
