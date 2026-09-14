<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A named set of commercial terms sellers can be put on.
     *
     * Three components, exactly as the brief fixes them: commission (a
     * percentage or a flat fee), an optional per-order add-on fee, and an
     * optional referral percentage. VAT and the reserve percentage are
     * deliberately NOT here — they are platform-wide facts that live in
     * settings, and a per-seller VAT rate would be a tax fiction.
     *
     * Percentages are stored as decimal strings rather than floats or
     * integers of basis points, because that is what Money::percentage()
     * parses exactly and what an administrator typed.
     *
     * Rows are editable and that is safe, because no order ever reads one:
     * the terms in force are snapshotted onto the order at payment time and
     * read from there forever after. Editing a policy changes what future
     * orders are charged and nothing else.
     */
    public function up(): void
    {
        Schema::create('monetisation_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->text('description')->nullable();

            $table->string('commission_type', 16)->default('percentage');
            $table->string('commission_percent', 8)->default('0.00')
                ->comment('Decimal string, e.g. "7.50". Applied to the goods value when the type is percentage.');
            $table->unsignedBigInteger('commission_flat_ngwee')->default(0);

            $table->unsignedBigInteger('addon_fee_ngwee')->default(0)
                ->comment('Charged once per order, on top of commission.');
            $table->string('referral_fee_percent', 8)->default('0.00')
                ->comment('Charged only on orders that arrived through a referral partner.');

            $table->boolean('is_default')->default(false)
                ->comment('The policy sellers fall back to. Exactly one row carries this.');
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monetisation_policies');
    }
};
