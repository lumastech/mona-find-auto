<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a seller's money is sent.
     *
     * Every field a fraudster could use to redirect a payout is encrypted at
     * rest, which is why the account-number columns are text rather than the
     * short strings they hold: ciphertext is far longer than the number.
     *
     * The last four digits are stored separately in the clear so a seller can
     * tell two accounts apart on screen without decrypting either.
     */
    public function up(): void
    {
        Schema::create('payout_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();

            $table->string('method', 16)->comment('PayoutMethod: bank or mobile_money.');
            $table->string('label', 60)->nullable()->comment('What the seller calls it.');

            /* Encrypted. Bank fields. */
            $table->text('beneficiary_name')->nullable();
            $table->text('account_number')->nullable();
            $table->text('bank_branch')->nullable();
            $table->text('bank_address')->nullable();
            $table->text('swift_code')->nullable();
            $table->text('tpin')->nullable();

            /* Encrypted. Mobile-money fields. */
            $table->text('mobile_number')->nullable();

            /* Clear. Routing and display values that carry no risk on their own. */
            $table->string('bank_code', 16)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('network', 16)->nullable()->comment('MobileNetwork: mtn or airtel.');
            $table->string('last_four', 4)->nullable();

            /*
             * What the gateway said when the account was checked. The
             * resolved name is the bank's, not the seller's — a mismatch is
             * the first thing a failed payout is argued about.
             */
            $table->string('resolved_name')->nullable();
            $table->string('lenco_recipient_id')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['seller_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_accounts');
    }
};
