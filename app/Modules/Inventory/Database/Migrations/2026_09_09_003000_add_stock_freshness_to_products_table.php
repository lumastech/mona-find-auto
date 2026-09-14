<?php

declare(strict_types=1);

use App\Modules\Inventory\Enums\FreshnessState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock freshness, which hangs off the listing rather than the variant.
     *
     * A seller confirms "yes, I still have this part", not "yes, I still have
     * the left-hand one" — the promise is about the shelf, so the timestamp
     * belongs to the listing that describes it.
     *
     * These columns live on `products`, which Catalog owns, because the
     * storefront has to be able to exclude a hidden listing in the same query
     * that finds it. A join for that on the busiest path in the application
     * would be a real cost for a purely notional separation.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->timestamp('freshness_confirmed_at')->nullable()->after('delivery_available')
                ->comment('When the seller last confirmed this stock is real.');
            $table->string('freshness_state', 20)->default(FreshnessState::Fresh->value)->after('freshness_confirmed_at')
                ->comment('Derived daily from freshness_confirmed_at and the freshness.* settings.');
            $table->timestamp('freshness_hidden_at')->nullable()->after('freshness_state')
                ->comment('When the listing dropped off the storefront for going unconfirmed.');

            /*
             * Which reminder the seller has already had. Day 3 and day 5 are
             * two different messages, and a seller who ignores the first must
             * not get it again the next morning.
             */
            $table->unsignedTinyInteger('stock_reminder_stage')->default(0)->after('freshness_hidden_at');
            $table->timestamp('stock_reminder_sent_at')->nullable()->after('stock_reminder_stage');

            /* The storefront's hot path, and the daily sweep's. */
            $table->index(['status', 'freshness_state']);
            $table->index(['seller_id', 'freshness_confirmed_at']);
        });

        /*
         * Existing listings have never been confirmed. Backdating them to
         * publication rather than to now is the honest reading: putting a
         * part up for sale is itself a claim to have it, and it starts the
         * clock where it should have started.
         */
        DB::table('products')->update([
            'freshness_confirmed_at' => DB::raw('COALESCE(published_at, created_at)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['status', 'freshness_state']);
            $table->dropIndex(['seller_id', 'freshness_confirmed_at']);
            $table->dropColumn([
                'freshness_confirmed_at',
                'freshness_state',
                'freshness_hidden_at',
                'stock_reminder_stage',
                'stock_reminder_sent_at',
            ]);
        });
    }
};
