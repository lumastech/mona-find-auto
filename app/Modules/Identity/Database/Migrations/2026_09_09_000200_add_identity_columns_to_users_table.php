<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Everything MonaFind needs about a person beyond a name and a password.
     *
     * `name` stays on the table because the framework, notifications and the
     * audit trail all read it; the model keeps it in step with the first and
     * last name rather than making callers assemble it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name')->after('id');
            $table->string('last_name')->after('first_name');

            /* E.164, so a login can look up exactly what was typed at registration. */
            $table->string('phone', 20)->nullable()->unique()->after('email');
            $table->string('phone_network', 16)->nullable()->after('phone')->comment('mtn|airtel|zamtel — routes mobile-money collections later.');
            $table->timestamp('phone_verified_at')->nullable()->after('phone_network');

            $table->string('status', 16)->default('pending')->index()->after('password');
            $table->text('status_reason')->nullable()->after('status')->comment('Why staff last changed the status; shown to the account holder.');
            $table->timestamp('status_changed_at')->nullable()->after('status_reason');

            /* The account's own postal address; delivery addresses live in user_addresses. */
            $table->foreignId('province_id')->nullable()->after('status_changed_at')->constrained()->nullOnDelete();
            $table->foreignId('city_id')->nullable()->after('province_id')->constrained()->nullOnDelete();
            $table->string('street')->nullable()->after('city_id');
            $table->string('plot_number', 60)->nullable()->after('street');

            $table->timestamp('last_seen_at')->nullable()->after('plot_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('province_id');
            $table->dropConstrainedForeignId('city_id');

            $table->dropUnique(['phone']);
            $table->dropColumn([
                'first_name',
                'last_name',
                'phone',
                'phone_network',
                'phone_verified_at',
                'status',
                'status_reason',
                'status_changed_at',
                'street',
                'plot_number',
                'last_seen_at',
            ]);
        });
    }
};
