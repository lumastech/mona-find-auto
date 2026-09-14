<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The banner across the top of an area, for a stated window.
 *
 * A window rather than an on/off switch, because the announcements that
 * matter are the ones nobody is awake for: "payments are down for maintenance
 * on Sunday 02:00–04:00" has to appear and disappear on its own. `is_active`
 * sits alongside the window as the kill switch — a banner that turns out to
 * be wrong comes down now, without editing its dates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 160);
            $table->text('body');

            $table->string('level', 16)->default('info')->comment('AnnouncementLevel.');
            $table->string('audience', 16)->default('everyone')->comment('AnnouncementAudience.');

            $table->string('link_url', 255)->nullable();
            $table->string('link_label', 60)->nullable();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable()->comment('Null runs until switched off.');
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /* The storefront asks "what is showing right now" on every page. */
            $table->index(['is_active', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
