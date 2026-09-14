<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One party's rating of another, attached to the thing that entitled them.
 *
 * The unique index on (source_type, source_id, direction) is the whole rule
 * the brief asks for — exactly one rating per direction per completed order
 * or endorsement — expressed where two simultaneous submissions cannot slip
 * past it. The service checks first so the buyer gets a sentence rather than
 * a constraint violation, but the database is what makes it true.
 *
 * Rater and ratee are both polymorphic because the parties are not all the
 * same kind of thing. A buyer is a User; a shop is a Seller, and a shop's
 * rating of a buyer belongs to the business rather than to whichever member
 * of staff typed it — which is why submitted_by records the human separately.
 *
 * `body` holds the text as published, after redaction. What the author
 * originally typed is deliberately NOT kept: the point of stripping a phone
 * number out of a review is that the platform stops holding it, and a
 * moderation table quietly retaining every redacted number would undo that.
 * `screen_flags` records what kind of thing was removed, which is all a
 * moderator needs to see a pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table): void {
            $table->id();

            $table->string('direction', 32)->comment('RatingDirection.');
            $table->string('source', 16)->comment('RatingSource: order or endorsement.');
            $table->morphs('source');

            /* Who gave it, and who it is about. */
            $table->morphs('rater');
            $table->morphs('ratee');

            /*
             * The person who actually typed it. Equal to the rater for a
             * buyer; a member of shop staff when the rater is a Seller.
             */
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();

            $table->unsignedTinyInteger('stars')->comment('1-5.');
            $table->text('body')->nullable()->comment('As published: redacted, never the raw text.');

            $table->string('status', 16)->default('published');
            $table->boolean('verified_purchase')->default(false);

            /*
             * What the automatic screen found. An array of ScreenFlag values;
             * empty for the reviews that went straight through.
             */
            $table->json('screen_flags')->nullable();

            /* One reply per rating, so it lives on the row rather than in a table. */
            $table->text('reply_body')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replied_at')->nullable();

            $table->unsignedInteger('reports_count')->default(0);

            $table->text('moderation_reason')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            /* One rating per direction per order or endorsement. */
            $table->unique(['source_type', 'source_id', 'direction'], 'ratings_source_direction_unique');

            /* A seller's or mechanic's public review list, newest first. */
            $table->index(['ratee_type', 'ratee_id', 'status', 'created_at'], 'ratings_ratee_status_index');

            /* The moderation queue. */
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
