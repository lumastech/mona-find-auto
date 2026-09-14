<?php

declare(strict_types=1);

use App\Modules\Ledger\Enums\LedgerAccountCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The chart of accounts.
     *
     * A lookup table rather than the source of truth: LedgerAccountCode is,
     * and the seeder copies it here. The table exists so journal lines can
     * carry a foreign key — a line pointing at an account that does not
     * exist is a broken ledger, and the database should be the thing that
     * says so.
     *
     * Which means the codes are NOT free text. Inserting a row here does not
     * create a usable account; only adding a case to the enum does.
     */
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique()
                ->comment('A LedgerAccountCode value. The enum is the source of truth.');
            $table->string('name');
            $table->string('type', 16)->comment('asset, liability, revenue or expense.');
            $table->string('normal_balance', 8)->comment('debit or credit — the side that increases this account.');
            $table->string('subject', 16)->default('none')
                ->comment('What this account is broken down by: none, order or seller.');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
