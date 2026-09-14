<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One cart, one payment, however many shops.
     *
     * A MonaFind cart routinely spans four sellers, and the buyer pays once.
     * The group is what the payment is addressed to; the orders inside it are
     * what the sellers see, and they run on entirely separate timetables
     * afterwards. Keeping the two apart is what lets one seller dispatch on
     * Monday while another is still being chased for a confirmation, without
     * either of them touching a payment that has already settled.
     *
     * `public_id` is a ULID rather than the auto-increment key because it
     * goes into the payment reference (MFA-{public_id}-{attempt}) and so is
     * handed to Lenco, printed on receipts and read out over the phone. An
     * incrementing id in that position tells anybody who looks how many
     * orders the platform has taken.
     */
    public function up(): void
    {
        Schema::create('order_groups', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique()
                ->comment('The id the outside world sees. Goes into the payment reference.');

            $table->foreignId('user_id')->constrained()->cascadeOnDelete()
                ->comment('The buyer. One payer per group, always.');

            $table->string('status', 32)->default('pending_payment');
            $table->string('payment_method', 16)->comment('PaymentMethod: card or mobile_money.');

            /*
             * The money, split the way the buyer sees it: parts, then
             * delivery, then the figure the widget opens on. All three are
             * VAT-inclusive; MonaFind's own VAT is on commission only and
             * never appears on a buyer's total.
             */
            $table->unsignedBigInteger('items_total_ngwee');
            $table->unsignedBigInteger('delivery_total_ngwee')->default(0);
            $table->unsignedBigInteger('total_ngwee');

            $table->unsignedTinyInteger('payment_attempts')->default(0)
                ->comment('Incremented by Payments for each collection started. Drives the reference suffix.');

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_groups');
    }
};
