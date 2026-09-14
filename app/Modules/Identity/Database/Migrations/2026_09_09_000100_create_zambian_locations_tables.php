<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zambia's provinces and their towns, seeded from a fixed reference list.
     *
     * Addresses point at these rather than storing free text so that seller
     * coverage areas, delivery zones and "nearest first" search all agree on
     * what a place is called.
     */
    public function up(): void
    {
        Schema::create('provinces', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('capital')->nullable();
            $table->unsignedSmallInteger('position')->default(0)->comment('Display order in address pickers.');
            $table->timestamps();
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('province_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_major')->default(false)->comment('Shown first in pickers; the towns most buyers live in.');
            $table->timestamps();

            $table->unique(['province_id', 'slug']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
        Schema::dropIfExists('provinces');
    }
};
