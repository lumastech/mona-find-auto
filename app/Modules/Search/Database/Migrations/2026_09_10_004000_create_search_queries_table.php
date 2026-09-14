<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What buyers looked for, and whether the platform had it.
 *
 * The zero-result rows are the point. A Zambian buyer searching "hilux vigo
 * bull bar" and finding nothing is telling MonaFind either that the reference
 * data is missing a model name or that nobody is stocking a part people want
 * — and both are actionable, but only if somebody wrote the search down.
 *
 * No IP address and no free-text personal data: the query, what was filtered
 * on, how many came back, and who searched when they were signed in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_queries', function (Blueprint $table): void {
            $table->id();

            /* Lowercased and trimmed, so "Brake Pads" and "brake pads " group. */
            $table->string('term')->nullable()->index();

            /** @var array<string, mixed> The applied facet filters, as sent. */
            $table->json('filters')->nullable();

            $table->string('sort')->default('recommended');
            $table->string('tier')->nullable();

            $table->unsignedInteger('result_count')->default(0);

            /*
             * Derived from result_count, stored anyway: the report groups on
             * it, and "where result_count = 0" cannot use an index that says
             * what the row means.
             */
            $table->boolean('zero_results')->default(false)->index();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_queries');
    }
};
