<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The listings index, kept in the application's own database.
 *
 * This is the `sql` Scout driver's storage — the fallback for hosting that
 * cannot run Meilisearch. One row per listing a buyer may open, flattened the
 * same way the Meilisearch document is (see Services\ProductDocument), so
 * every filter the storefront offers is a plain indexed column rather than a
 * join across four modules' tables.
 *
 * Nothing in here is a float. The quality score is stored in hundredths and
 * coordinates in millionths of a degree, because PDO binds a PHP float as a
 * string and SQLite then compares it wrongly against every REAL column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_listings', function (Blueprint $table): void {
            $table->foreignId('product_id')->primary()->constrained()->cascadeOnDelete();

            /*
             * Lowercased words with a space either side of each, so a token
             * is matched as `% word %` (or `% word%` for a half-typed last
             * word) and "pad" never matches inside "keypad".
             */
            $table->text('search_text');

            /* The narrower text a result's match tier is judged against. */
            $table->text('match_text');

            $table->unsignedBigInteger('category_id');
            /* The category's materialised path, so a subtree is one prefix LIKE. */
            $table->string('category_path')->index();

            $table->unsignedBigInteger('make_id')->nullable()->index();
            $table->unsignedBigInteger('vehicle_model_id')->nullable()->index();
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();

            $table->string('condition');
            $table->boolean('inspected');
            $table->string('sourcing');

            $table->unsignedBigInteger('price_ngwee')->nullable()->index();
            $table->boolean('in_stock');
            $table->boolean('delivery_available');

            $table->unsignedBigInteger('seller_id')->index();
            $table->string('seller_type');
            $table->boolean('seller_verified');

            $table->unsignedBigInteger('province_id')->nullable()->index();
            $table->unsignedBigInteger('city_id')->nullable()->index();

            $table->unsignedInteger('quality_hundredths')->index();
            $table->unsignedBigInteger('published_timestamp')->nullable();

            $table->integer('latitude_e6')->nullable()->index();
            $table->integer('longitude_e6')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_listings');
    }
};
