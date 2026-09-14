<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A seller's published policies, versioned.
     *
     * Editing a policy writes a new row rather than changing the old one:
     * buyers accept a specific version at checkout, and the version they
     * agreed to has to still be readable when a dispute is argued months
     * later. Exactly one row per seller and type is current.
     */
    public function up(): void
    {
        Schema::create('seller_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();

            $table->string('type', 32)->comment('PolicyType: delivery, refund, warranty, terms.');
            $table->unsignedInteger('version')->default(1);
            $table->longText('body')->comment('Rich text, sanitised on save.');
            $table->timestamp('effective_from');
            $table->boolean('is_current')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['seller_id', 'type', 'version']);
            $table->index(['seller_id', 'type', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_policies');
    }
};
