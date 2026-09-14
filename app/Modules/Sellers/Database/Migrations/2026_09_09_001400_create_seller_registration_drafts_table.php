<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A part-finished seller sign-up.
     *
     * Signing up asks for documents and bank details most people do not have
     * to hand, so the wizard is resumable: each step is saved as it is
     * completed and the draft is turned into a Seller only at submit.
     *
     * One draft per account — a person runs one business here.
     */
    public function up(): void
    {
        Schema::create('seller_registration_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('current_step', 32)->default('type');
            $table->string('furthest_step', 32)->default('type')
                ->comment('How far the applicant has ever got, so going back does not lock them out.');
            $table->json('data')->comment('Everything typed so far, keyed by step.');

            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('seller_id')->nullable()->constrained()->nullOnDelete()
                ->comment('Set when the draft became a real seller.');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_registration_drafts');
    }
};
