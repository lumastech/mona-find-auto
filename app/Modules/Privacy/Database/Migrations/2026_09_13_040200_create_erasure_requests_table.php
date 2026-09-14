<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One person's request to be erased, and what happened to it.
     *
     * ## Why the user_id survives the erasure it describes
     *
     * This row is the platform's proof that it did what it was asked, and it
     * has to outlive the personal data it names. That works because the
     * `users` row is never deleted — it is anonymised in place, keeping its
     * id so that orders, ledger lines and audit entries still point at a real
     * account. The id of an erased account is not personal data: it is a
     * number MonaFind assigned, and it identifies nobody once the columns
     * around it hold tombstones.
     *
     * It also means `cascadeOnDelete` below is a formality that should never
     * fire. If a `users` row were ever genuinely deleted, the append-only
     * triggers on terms_acceptances would abort the cascade first.
     *
     * ## The grace period
     *
     * `erase_after` is set a configurable number of days ahead so that a
     * person who clicks Delete in anger, or whose session is hijacked, can
     * still call it off. The scheduled job only erases requests past that
     * moment.
     *
     * ## report
     *
     * A per-table tally of what each module removed, written at completion.
     * It is what turns "we erased you" into an answer to "what exactly did
     * you erase", which is the question a regulator asks.
     */
    public function up(): void
    {
        Schema::create('erasure_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('status', 20)->comment('App\Modules\Privacy\Enums\ErasureStatus');

            $table->text('reason')->nullable()->comment('Optional; what the person told us.');

            $table->timestamp('requested_at');
            $table->timestamp('erase_after')->comment('End of the grace period.');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            /* Set when staff hold the request: an open order, a live dispute. */
            $table->text('blocked_reason')->nullable();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->json('report')->nullable()->comment('Per-table tally of what was erased.');

            $table->timestamps();

            /* The scheduled sweep: open requests whose grace period has run out. */
            $table->index(['status', 'erase_after']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erasure_requests');
    }
};
