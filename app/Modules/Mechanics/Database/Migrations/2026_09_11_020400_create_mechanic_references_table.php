<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People a reviewer may ring about an applicant.
 *
 * Optional, and never public. These rows hold a third party's phone number
 * given to MonaFind for one purpose — checking a claim — and that person has
 * not agreed to appear on a directory page. Nothing in the storefront, the
 * seller portal or /api/v1 reads this table; MechanicProfileResource has no
 * branch that could put it on a page, and the admin screen is the only
 * surface that loads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mechanic_references', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('mechanic_profile_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('relationship')->nullable()->comment('Former employer, workshop owner, customer.');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->text('note')->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['mechanic_profile_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mechanic_references');
    }
};
