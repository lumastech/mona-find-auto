<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each shop is willing to do about getting a part to a buyer.
     *
     * These columns sit on `sellers` but belong to Orders, in the same way
     * the freshness columns sit on `products` and belong to Inventory: the
     * module that reads and writes them owns the migration, and no other
     * module touches them.
     *
     * Release 1 charges one flat delivery fee per seller. Per-zone and
     * distance-based pricing are a 1.x concern and will arrive as further
     * implementations of DeliveryFeeStrategy rather than as more columns
     * here — which is why the fee is a plain integer and not a rate table.
     */
    public function up(): void
    {
        Schema::table('sellers', function (Blueprint $table): void {
            $table->boolean('offers_pickup')->default(true)->after('opening_hours')
                ->comment('Whether buyers may collect from the trading address.');

            $table->boolean('offers_delivery')->default(false)->after('offers_pickup')
                ->comment('Whether the seller sends parts out at all.');

            $table->unsignedBigInteger('delivery_fee_ngwee')->default(0)->after('offers_delivery')
                ->comment('Flat delivery charge per order, in ngwee. Release 1 pricing.');

            $table->text('delivery_note')->nullable()->after('delivery_fee_ngwee')
                ->comment('Shown at checkout beside the delivery option, e.g. "Lusaka only, next day".');
        });
    }

    public function down(): void
    {
        Schema::table('sellers', function (Blueprint $table): void {
            $table->dropColumn(['offers_pickup', 'offers_delivery', 'delivery_fee_ngwee', 'delivery_note']);
        });
    }
};
