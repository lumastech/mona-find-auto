<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The controlled list of things a mechanic can say they do.
 *
 * A list rather than free text, because the whole point of the directory is
 * that a buyer with a broken gearbox can filter to the people who rebuild
 * gearboxes. Free text gives you "gearbox", "gear box", "Gearbox repairs" and
 * "transmission" as four different specialities and no working filter.
 *
 * Seeded by MechanicSpecialitySeeder and extended by staff. Rows are
 * deactivated rather than deleted — a speciality somebody's approved profile
 * already claims must not vanish from underneath it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mechanic_specialities', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            /* The directory's filter list: active ones, in the order staff chose. */
            $table->index(['is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mechanic_specialities');
    }
};
