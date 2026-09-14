<?php

declare(strict_types=1);

use App\Support\Database\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One side of one movement.
     *
     * `amount_ngwee` is unsigned, which is the schema stating the rule the
     * value objects state too: a line carries a positive amount and a
     * direction, never a negative amount. Allowing a signed amount would let
     * the same movement be written two ways and make "do the debits equal the
     * credits" a question a sign error can pass.
     *
     * `subject_type`/`subject_id` are the dimension every useful balance is
     * asked along — escrow held FOR AN ORDER, payable TO A SELLER. Reading
     * those off the lines is what lets the materialised balances be checked
     * against the truth rather than trusted.
     *
     * Append-only, like the entries they belong to.
     */
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained();

            $table->string('direction', 8)->comment('debit or credit.');
            $table->unsignedBigInteger('amount_ngwee')->comment('Always positive. The direction says which way it went.');

            /* Which order's escrow, which seller's payable. Null on platform-wide accounts. */
            $table->nullableMorphs('subject');

            $table->string('memo')->nullable();
            $table->timestamp('posted_at');
            $table->timestamp('created_at')->nullable();

            /* The index the balance recomputation and the account browser both run on. */
            $table->index(['ledger_account_id', 'subject_type', 'subject_id'], 'journal_lines_balance_index');
            $table->index(['ledger_account_id', 'posted_at']);
        });

        AppendOnlyTable::protect('journal_lines');
    }

    public function down(): void
    {
        AppendOnlyTable::unprotect('journal_lines');

        Schema::dropIfExists('journal_lines');
    }
};
