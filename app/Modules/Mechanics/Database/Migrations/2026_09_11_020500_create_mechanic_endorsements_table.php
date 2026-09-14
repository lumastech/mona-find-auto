<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shop vouching for a mechanic.
 *
 * One row per mechanic-and-seller pair, enforced by the unique index, and it
 * is reused rather than replaced: a request that was declined in March and
 * granted in November is the same relationship at two points in time, and a
 * table that accumulated a row per attempt would make "is this mechanic
 * endorsed by this shop" a question about ordering rather than a lookup.
 *
 * The decision columns are kept apart from the request columns on purpose.
 * `requested_by` is the mechanic's account; `decided_by` is the member of
 * shop staff who answered, which is not the same person and is the one a
 * dispute about an endorsement will ask about.
 *
 * Endorsements are also a rating source — see EndorsementRatingSource — which
 * is why this row is what a Seller→Mechanic rating hangs off. Revoking does
 * not delete the row, so a rating written while the endorsement stood keeps
 * the thing that entitled it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mechanic_endorsements', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('mechanic_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();

            $table->string('status', 16)->default('requested')->comment('EndorsementStatus.');

            $table->text('message')->nullable()->comment('What the mechanic wrote when asking.');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('requested_at')->nullable();

            $table->text('response_note')->nullable()->comment('Shown to the mechanic.');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            $table->timestamp('endorsed_at')->nullable()->comment('When the badge last went up.');
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();

            $table->timestamps();

            $table->unique(['mechanic_profile_id', 'seller_id'], 'mechanic_endorsements_pair_unique');

            /* The seller portal's queue: what this shop has been asked, newest first. */
            $table->index(['seller_id', 'status', 'requested_at']);
            /* The badges on one profile. */
            $table->index(['mechanic_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mechanic_endorsements');
    }
};
