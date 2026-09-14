<?php

declare(strict_types=1);

use App\Support\Database\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One balanced movement of money.
     *
     * The column that does the work is `idempotency_key`, and its unique
     * index is the platform's entire defence against posting the same
     * business event twice. Lenco delivers webhooks more than once as a
     * matter of course, queued listeners are retried after timeouts, and
     * operators click buttons twice; without this a single settled payment
     * credits a seller two or three times and nothing anywhere notices.
     *
     * It is a UNIQUE INDEX rather than a check in PHP deliberately. Two
     * webhook deliveries can be in flight in different workers at the same
     * instant, and "select then insert" loses that race — the database
     * rejecting the second insert does not.
     *
     * Append-only in both layers. A ledger whose entries can be edited is a
     * spreadsheet.
     */
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->comment('The public identifier, safe to quote in an invoice or an email.');

            $table->string('idempotency_key', 191)->unique()
                ->comment('Names the business event, e.g. "escrow-release:order:4012". One event, one entry, forever.');

            $table->string('recipe', 32)->comment('A PostingRecipe value — why this entry exists.');
            $table->string('description');

            /* What the movement was about: an Order, Payment, Payout, Refund or LedgerAdjustment. */
            $table->nullableMorphs('reference');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Null for anything the platform did on its own — most entries.');
            $table->string('actor_label')->default('System');

            $table->unsignedBigInteger('total_ngwee')
                ->comment('The debit total, which equals the credit total. Denormalised so a browser listing needs no join.');

            $table->json('context')->nullable();

            $table->timestamp('posted_at')->comment('When the money moved, which is not always when the row was written.');
            $table->timestamp('created_at')->nullable();

            $table->index(['recipe', 'posted_at']);
            $table->index('posted_at');
        });

        AppendOnlyTable::protect('journal_entries');
    }

    public function down(): void
    {
        AppendOnlyTable::unprotect('journal_entries');

        Schema::dropIfExists('journal_entries');
    }
};
