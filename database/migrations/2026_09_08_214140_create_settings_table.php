<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Typed key/value store behind the settings() helper. Everything the brief
     * calls "configurable in admin" — escrow windows, freshness thresholds,
     * ranking weights, commission defaults — lives here rather than in .env.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('group')->index();
            $table->string('type', 32)->default('string');
            $table->text('value')->nullable();
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false)->comment('Safe to expose to the browser.');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
