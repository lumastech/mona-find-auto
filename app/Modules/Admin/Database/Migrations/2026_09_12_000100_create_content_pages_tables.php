<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CMS-lite: the handful of pages MonaFind writes about itself.
 *
 * Two tables rather than one, because of the platform terms. Every buyer's
 * checkout acceptance records the terms VERSION they agreed to, and an
 * administrator editing the wording afterwards must not silently restate what
 * past buyers were promised — so the page carries a pointer to its current
 * version and each version's body is kept for ever.
 *
 * That applies to About and the FAQ too, at no extra cost: "what did this
 * page say in March" is answerable for all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('title', 160);
            $table->string('status', 16)->default('draft')->comment('ContentPageStatus.');

            /*
             * A system page is one the platform itself reads — the terms body
             * the checkout modal shows, the privacy notice linked from the
             * footer. Staff may rewrite one but may not delete it, because
             * something elsewhere would then have nothing to render.
             */
            $table->boolean('is_system')->default(false);
            $table->boolean('show_in_footer')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->string('meta_description', 320)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'position']);
        });

        Schema::create('content_page_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_page_id')->constrained()->cascadeOnDelete();

            /* Monotonic per page, and what policies.platform_terms_version tracks. */
            $table->unsignedInteger('version');

            $table->string('title', 160);
            $table->longText('body');
            $table->string('change_note', 255)->nullable()
                ->comment('Why this edit was made. Required by the console.');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_label', 120)->nullable()
                ->comment('Kept so a deleted staff account does not erase the authorship.');

            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['content_page_id', 'version']);
        });

        Schema::table('content_pages', function (Blueprint $table): void {
            $table->foreignId('current_version_id')->nullable()->after('status')
                ->constrained('content_page_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_pages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_version_id');
        });

        Schema::dropIfExists('content_page_versions');
        Schema::dropIfExists('content_pages');
    }
};
