<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Laravel's own notification store, which backs the "in-app" half of
     * every notification the platform sends.
     *
     * It lives in the core migrations rather than in a module because it is
     * shared infrastructure in the same way `settings` and `audit_logs` are:
     * Inventory writes stock reminders here, Orders and Messaging will write
     * theirs to the same table, and the bell in the portal header reads all
     * of them at once.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            /* The unread badge: one person's unread notifications, newest first. */
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
