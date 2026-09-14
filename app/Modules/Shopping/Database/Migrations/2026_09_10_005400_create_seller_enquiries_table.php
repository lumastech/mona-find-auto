<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Contact seller" — the stub of a message thread.
     *
     * Full in-platform messaging is the Messaging module's job. What this
     * table exists for is the promise the storefront makes today: a buyer who
     * presses Contact seller has actually reached the seller, and can see
     * that they did, rather than being handed a phone number and left to it.
     *
     * It is written through App\Modules\Shopping\Contracts\SellerEnquiryChannel,
     * so when Messaging arrives and binds a real thread-backed implementation
     * the storefront changes in one place and these rows stay as the record of
     * what was sent before it did.
     */
    public function up(): void
    {
        Schema::create('seller_enquiries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()
                ->comment('The buyer who wrote. Guests cannot: there is no thread to put a reply in.');
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete()
                ->comment('The listing the enquiry came from, if it came from one.');

            $table->text('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            /* A seller's enquiries, newest first; and a buyer's own thread list. */
            $table->index(['seller_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_enquiries');
    }
};
