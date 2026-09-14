<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A selling business on MonaFindAuto.
     *
     * One account owns one seller; staff on that business hold the
     * seller-staff role against the same record. The verification status and
     * the payment mode both live here because both are staff-set and both are
     * read on every listing.
     */
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete()
                ->comment('The account that owns this business.');

            $table->string('type', 8)->comment('SellerType: APS, SPS, G, CB, AMR, WO, CD.');
            $table->string('business_name');
            $table->string('slug')->unique();
            $table->string('registration_number', 60)->nullable()
                ->comment('PACRA number. Optional at sign-up, required before the Verified badge.');
            $table->text('description')->nullable();

            /* Where the business trades from. Buyers are shown this; couriers quote from it. */
            $table->foreignId('province_id')->constrained();
            $table->foreignId('city_id')->constrained();
            $table->string('street');
            $table->string('plot_number', 60)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('place_id')->nullable();
            $table->string('formatted_address')->nullable();

            /* Contact details. Blurred for guests, shown to logged-in buyers. */
            $table->string('phone', 20);
            $table->string('email');
            $table->string('contact_person');

            $table->unsignedSmallInteger('bay_count')->nullable()
                ->comment('Service bays on site. Asked of garages, workshops and breakers only.');

            $table->json('opening_hours')->nullable()
                ->comment('Keyed by weekday: {"mon": {"open": "08:00", "close": "17:00"}}.');

            $table->string('verification_status', 32)->default('draft');
            $table->text('verification_note')->nullable()->comment('The reviewer checklist notes on the current decision.');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspection_scheduled_for')->nullable();

            /*
             * Money placeholders. Both are staff-set and snapshotted onto
             * each order at payment time; the monetisation policy itself is
             * built by the Finance module, so the column carries no foreign
             * key yet.
             */
            $table->string('payment_mode', 16)->default('escrow');
            $table->unsignedBigInteger('monetisation_policy_id')->nullable()
                ->comment('Set by Finance. Null means the seller is on the platform default.');

            $table->timestamps();

            $table->index(['verification_status', 'created_at']);
            $table->index(['province_id', 'city_id']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};
