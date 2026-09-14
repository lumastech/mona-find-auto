<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a seller should be told they are running out.
     *
     * The threshold is nullable rather than defaulted so that a seller who
     * never set one keeps following the platform default as an administrator
     * changes it. A column defaulted to 2 would freeze every existing variant
     * at whatever the default happened to be on the day it was created.
     *
     * The two alert timestamps exist so a shop with four hundred listings is
     * not sent four hundred emails a day about the same four hundred shelves:
     * an alert fires when a variant crosses into the state, and again only
     * after it has climbed back out of it.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unsignedInteger('low_stock_threshold')->nullable()->after('quantity')
                ->comment('Null follows the stock.low_stock_threshold setting.');
            $table->timestamp('low_stock_alerted_at')->nullable()->after('low_stock_threshold');
            $table->timestamp('out_of_stock_alerted_at')->nullable()->after('low_stock_alerted_at');

            /* The seller portal's stock list, ordered by what needs attention. */
            $table->index('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropIndex(['quantity']);
            $table->dropColumn([
                'low_stock_threshold',
                'low_stock_alerted_at',
                'out_of_stock_alerted_at',
            ]);
        });
    }
};
