<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A seller's reputation, precomputed.
 *
 * This table exists because of one caller: the nightly search re-index asks
 * for every seller's rating, review count and dispute rate at once. Computing
 * that from the ratings and orders tables per seller turns a rebuild into
 * tens of thousands of aggregate queries, so the aggregate is maintained when
 * ratings and disputes change and read back as a single row.
 *
 * It is a cache, not a source of truth: RecomputeAllTrustScores rebuilds every
 * row nightly, so a missed event costs a day of staleness rather than a
 * permanently wrong number.
 *
 * The star breakdown is kept as counts per star rather than derived from the
 * average, because the storefront draws five bars and an average cannot be
 * turned back into them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_trust_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->unique()->constrained()->cascadeOnDelete();

            $table->unsignedInteger('ratings_count')->default(0);
            $table->decimal('average_stars', 3, 2)->nullable();
            $table->json('star_breakdown')->nullable()->comment('Counts keyed 1-5.');

            $table->decimal('dispute_rate_percent', 5, 2)->default(0);
            $table->unsignedInteger('completed_orders')->default(0);

            $table->decimal('trust_score', 5, 2)->default(0);
            $table->string('trust_band', 16)->default('fair');

            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            /* The admin review list: worst first. */
            $table->index(['trust_band', 'trust_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_trust_scores');
    }
};
