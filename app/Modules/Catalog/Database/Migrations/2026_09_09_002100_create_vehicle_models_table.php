<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A model within a make: Toyota Hilux, Nissan Hardbody.
     *
     * The production years are carried here so the listing form can offer a
     * sane year range instead of every year since 1900, and so an obviously
     * impossible fitment can be flagged.
     */
    public function up(): void
    {
        Schema::create('vehicle_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('make_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('body_type', 32)->nullable()->comment('BodyType, when the model only comes in one shape.');
            $table->unsignedSmallInteger('production_start_year')->nullable();
            $table->unsignedSmallInteger('production_end_year')->nullable()
                ->comment('Null means still in production.');
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            /* Slugs are unique per make: "Corolla" belongs to Toyota alone. */
            $table->unique(['make_id', 'slug']);
            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_models');
    }
};
