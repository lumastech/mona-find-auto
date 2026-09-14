<?php

declare(strict_types=1);

use App\Support\Database\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the buyer agreed to, exactly, at the moment they agreed to it.
     *
     * The whole table exists for one scenario: a buyer disputes an order, the
     * seller's refund policy is quoted at them, and it turns out the seller
     * rewrote that policy the week after the sale. `policies` records the id
     * AND the version of every document that was on screen, so the text can
     * be recovered from seller_policies even though a newer version has since
     * taken over as current.
     *
     * The platform's own minimum refund rule is copied in as text rather than
     * referenced, because it lives in the settings table where it has no
     * version history at all — a setting an administrator edits would
     * otherwise silently restate what every past buyer was promised.
     *
     * Append-only in both layers. A consent record that can be edited is not
     * a consent record.
     */
    public function up(): void
    {
        Schema::create('terms_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();

            $table->json('policies')
                ->comment('[{"policy_id": 12, "type": "refund", "version": 3, "effective_from": "..."}]');

            $table->string('platform_terms_version', 32)->nullable()
                ->comment('The MonaFind terms of use in force, as published in settings.');
            $table->unsignedSmallInteger('minimum_refund_days');
            $table->text('minimum_refund_statement');

            /* Who agreed, from where. What makes this evidence rather than a flag. */
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('accepted_at');

            $table->timestamp('created_at')->nullable();

            $table->index('order_id');
            $table->index(['user_id', 'accepted_at']);
        });

        AppendOnlyTable::protect('terms_acceptances');
    }

    public function down(): void
    {
        AppendOnlyTable::unprotect('terms_acceptances');

        Schema::dropIfExists('terms_acceptances');
    }
};
