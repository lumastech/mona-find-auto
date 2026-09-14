<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Google and Facebook identities linked to a MonaFind account.
     *
     * A person may link both, so this is a separate table rather than a pair
     * of columns on users. Tokens are encrypted; we keep them only so a
     * future feature can refresh a profile photo without a fresh consent.
     */
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 32)->comment('google|facebook');
            $table->string('provider_id');
            $table->string('email')->nullable();
            $table->string('nickname')->nullable();
            $table->string('avatar_url')->nullable();

            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            $table->timestamps();

            /* One identity belongs to exactly one account, and an account links each provider once. */
            $table->unique(['provider', 'provider_id']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
