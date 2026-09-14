<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a mechanic has worked.
 *
 * Rows rather than a text area, because a reviewer checking a claim needs to
 * see employer, role and dates as separate things, and because the profile
 * page renders it as a list. Dates are months rather than days — nobody
 * remembers the day they started at a garage in 2014 — so they are stored as
 * the first of the month and rendered as "Mar 2014".
 *
 * `is_current` exists instead of a null `ended_on` meaning two different
 * things: a job with no end date because it has not ended, and one with no
 * end date because the applicant did not fill it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mechanic_work_histories', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('mechanic_profile_id')->constrained()->cascadeOnDelete();

            $table->string('employer');
            $table->string('role');
            $table->text('description')->nullable();

            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->boolean('is_current')->default(false);

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['mechanic_profile_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mechanic_work_histories');
    }
};
