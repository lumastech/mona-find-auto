<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time codes sent over SMS.
     *
     * The code itself is hashed: a leaked database should not let anyone
     * complete a verification or a password reset. Attempts are counted on
     * the row so a code dies after three wrong guesses rather than staying
     * guessable for its full ten minutes.
     */
    public function up(): void
    {
        Schema::create('phone_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('phone', 20)->index();
            $table->string('purpose', 32)->comment('phone_verification|password_reset — a code is never valid for the other.');
            $table->string('code_hash');

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('last_sent_at')->nullable()->comment('Drives the resend throttle.');

            $table->string('request_ip', 45)->nullable();

            $table->timestamps();

            $table->index(['phone', 'purpose', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_verifications');
    }
};
