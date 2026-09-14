<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `sellers.monetisation_policy_id` was created without a foreign key
     * because the table it points at did not exist yet. It does now.
     *
     * Null still means "on the platform default", which is where the great
     * majority of sellers stay. Deleting a policy nulls the assignment
     * rather than cascading, so removing a policy quietly puts its sellers
     * back on the default instead of removing the sellers.
     *
     * SQLite cannot add a constraint to a table that already exists — the
     * only way is to rebuild the table, which is not worth doing to the
     * sellers table for a constraint the test suite does not rely on. So the
     * key is added on MySQL, where production runs, and skipped there.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('sellers', function (Blueprint $table): void {
            $table->foreign('monetisation_policy_id')
                ->references('id')
                ->on('monetisation_policies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('sellers', function (Blueprint $table): void {
            $table->dropForeign(['monetisation_policy_id']);
        });
    }
};
