<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somebody pressing "Report" on a review.
 *
 * A separate row per report rather than a counter on the rating, because a
 * moderator deciding whether a review should come down needs to see who
 * objected and what they said — three reports from the seller's own staff and
 * three from unrelated buyers are not the same evidence.
 *
 * One report per person per rating: pressing the button twice is not twice
 * the objection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rating_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rating_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();

            $table->string('reason', 32)->comment('ReportReason.');
            $table->text('details')->nullable();

            $table->string('status', 16)->default('open');
            $table->text('resolution_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->unique(['rating_id', 'reported_by']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_reports');
    }
};
