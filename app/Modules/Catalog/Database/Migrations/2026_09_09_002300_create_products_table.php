<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A listing: one part, offered by one seller.
     *
     * Two independent badges live on this row and are shown together on every
     * card and page. `condition` says what the part is — and is forced to
     * car_breaker for breaker sellers. `inspection_status` says whether
     * MonaFind looked at it, defaults to uninspected, and only staff may
     * change it.
     *
     * Price is not here: it belongs to the variant, because a product with
     * one variant and a product with five must be priced the same way.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description');

            /* Fitment. A universal part fits everything, so both are nullable. */
            $table->foreignId('make_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_model_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable()
                ->comment('Equal to year_from for a single year; both null for a universal part.');

            /* The two badges. Independent, and both always rendered. */
            $table->string('condition', 20)->comment('Condition: brand_new, used, car_breaker.');
            $table->string('inspection_status', 20)->default('uninspected')
                ->comment('Staff-set only. Defaults to uninspected and is audited on every change.');
            $table->timestamp('inspected_at')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('sourcing', 20)->comment('PartSourcing: oem or aftermarket.');

            /* Part identity. The number is what a mechanic actually searches by. */
            $table->string('part_number', 80)->nullable();
            $table->string('oem_number', 80)->nullable();

            /* Vehicle specification, all optional — a wiper blade needs none of it. */
            $table->unsignedSmallInteger('engine_size_cc')->nullable();
            $table->string('engine_code', 40)->nullable();
            $table->string('fuel_type', 20)->nullable();
            $table->string('transmission', 20)->nullable();
            $table->string('drive_type', 8)->nullable();
            $table->string('body_type', 32)->nullable();
            $table->string('trim', 60)->nullable()->comment('Trim or variant, e.g. "GL", "Legend 45".');
            $table->text('chassis_compatibility')->nullable()
                ->comment('Chassis or VIN codes this part is known to fit, free text.');

            $table->text('warranty_text')->nullable();
            $table->boolean('delivery_available')->default(false);

            /* Lifecycle. Every move goes through ListingModerationService. */
            $table->string('status', 20)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('rejection_fields')->nullable()
                ->comment('Per-field reasons from the last rejection, keyed by form field.');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            /* The storefront's hot path: published listings in a category. */
            $table->index(['status', 'category_id']);
            $table->index(['seller_id', 'status']);
            $table->index(['make_id', 'vehicle_model_id']);
            $table->index(['status', 'inspection_status']);
            $table->index('part_number');
            $table->index('condition');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
