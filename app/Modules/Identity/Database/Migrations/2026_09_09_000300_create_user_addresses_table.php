<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A buyer's saved delivery addresses.
     *
     * The recipient is stored per address because parts are routinely
     * delivered to a mechanic or a workshop rather than to the buyer.
     */
    public function up(): void
    {
        Schema::create('user_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('label', 60)->comment('What the buyer calls it: Home, Workshop, Mum.');
            $table->string('recipient_name');
            $table->string('recipient_phone', 20);

            $table->foreignId('province_id')->constrained();
            $table->foreignId('city_id')->constrained();
            $table->string('street');
            $table->string('plot_number', 60)->nullable();
            $table->text('directions')->nullable()->comment('Free text for couriers: landmarks, gate colour.');

            /* An optional pin dropped on the map; deliveries are quoted from it when present. */
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('place_id')->nullable();
            $table->string('formatted_address')->nullable();

            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_addresses');
    }
};
