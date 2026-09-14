<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Running totals, kept in step by the poster.
     *
     * A cache and nothing more. Every figure here is recoverable by summing
     * `journal_lines`, and LedgerBalances can do exactly that on demand —
     * which is the point: a materialised balance nobody can check against the
     * lines is a number nobody should trust. The consistency test sums both
     * ways after a thousand random postings and expects them to agree.
     *
     * It exists because the alternative is summing every line a seller has
     * ever had before showing them what they are owed, and that query gets
     * slower every day the platform trades.
     *
     * Two rows are maintained per line: the per-subject row (this order's
     * escrow) and the account-wide row with a null subject (all escrow held).
     * Deriving the account total by summing the subject rows would work until
     * the first platform-wide account, which has no subject rows at all.
     *
     * `balance_ngwee` is SIGNED against the account's normal balance, so a
     * positive escrow balance means money is held and a negative seller
     * payable means the seller owes the platform after a clawback. Storing an
     * unsigned figure plus a side would push that reading into every caller.
     */
    public function up(): void
    {
        Schema::create('ledger_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ledger_account_id')->constrained()->cascadeOnDelete();

            /*
             * The subject is carried twice on purpose. The type/id pair is
             * what a query joins and reads; `subject_key` is what the unique
             * index runs on, because MySQL treats every NULL as distinct and
             * a nullable pair would happily accept ten account-wide rows.
             */
            $table->nullableMorphs('subject');
            $table->string('subject_key', 191)->default('platform')
                ->comment('"platform" for the account-wide row, else "{morph class}:{id}".');

            $table->unsignedBigInteger('debit_ngwee')->default(0);
            $table->unsignedBigInteger('credit_ngwee')->default(0);
            $table->bigInteger('balance_ngwee')->default(0)
                ->comment('Signed against the account\'s normal balance. Negative means the account is the wrong way round.');

            $table->unsignedInteger('line_count')->default(0);
            $table->unsignedBigInteger('last_journal_line_id')->nullable();
            $table->timestamp('last_posted_at')->nullable();
            $table->timestamps();

            $table->unique(['ledger_account_id', 'subject_key'], 'ledger_balances_account_subject_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_balances');
    }
};
