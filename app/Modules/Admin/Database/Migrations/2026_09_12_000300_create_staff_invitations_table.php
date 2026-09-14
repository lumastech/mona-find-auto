<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invitation to join MonaFind staff.
 *
 * Staff accounts are never self-service: a moderator can unpublish any shop's
 * stock and a finance user can move money, so the only way into those roles
 * is an invitation a platform administrator sent to a named address.
 *
 * The token is stored hashed for the same reason a password is — a leaked
 * backup of this table must not hand anybody a moderator account — and it
 * expires, because an invitation left open for a year is a standing offer to
 * whoever inherits that mailbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('name', 120);
            $table->string('role', 32)->comment('Role enum: moderator, finance, platform-admin.');

            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');

            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['email', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_invitations');
    }
};
