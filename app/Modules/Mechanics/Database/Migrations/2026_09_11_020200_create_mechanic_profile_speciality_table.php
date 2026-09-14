<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which controlled specialities a profile claims.
 *
 * A plain pivot with no timestamps: the claim carries no history worth
 * keeping, and the profile's audit rows already record every edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mechanic_profile_speciality', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('mechanic_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mechanic_speciality_id')->constrained()->cascadeOnDelete();

            $table->unique(
                ['mechanic_profile_id', 'mechanic_speciality_id'],
                'mechanic_profile_speciality_unique',
            );

            /* "Everyone who does gearboxes" — the directory's speciality filter. */
            $table->index('mechanic_speciality_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mechanic_profile_speciality');
    }
};
