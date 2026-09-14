<?php

declare(strict_types=1);

use App\Support\Database\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable record of every staff action and every money movement.
     *
     * Rows are inserted and never touched again; database triggers reject
     * UPDATE and DELETE so the trail cannot be rewritten even from a console.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();

            $table->nullableMorphs('actor');
            $table->string('actor_label')->nullable()->comment('Denormalised actor name, readable after the actor is gone.');

            $table->string('action')->index();
            $table->nullableMorphs('subject');

            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->json('context')->nullable()->comment('Request metadata: ip, user agent, route, batch id.');

            $table->timestamp('created_at')->index();

            $table->index(['action', 'created_at']);
        });

        AppendOnlyTable::protect('audit_logs');
    }

    public function down(): void
    {
        AppendOnlyTable::unprotect('audit_logs');

        Schema::dropIfExists('audit_logs');
    }
};
