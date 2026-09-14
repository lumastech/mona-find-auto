<?php

declare(strict_types=1);

use App\Support\Database\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each person agreed to, when, and to which version of the document.
     *
     * The Data Protection Act 2021 puts the burden of proving consent on the
     * controller. A boolean column on `users` proves nothing: it says what is
     * true now, not what was agreed then, and it is one UPDATE away from
     * saying something else.
     *
     * So consent is a log. Every grant and every withdrawal is a row, the
     * current position is the newest row for that (user, type), and the table
     * is append-only in both layers — an editable consent record is not
     * evidence of anything.
     *
     * ## Why the document version is stored, not referenced
     *
     * `document_version` is copied from the content page at the moment of
     * consent. Staff publish new versions of the privacy notice; the point of
     * the record is that it can still say which words this person saw, after
     * the page has moved on three times.
     *
     * ## No unique constraint
     *
     * Deliberately none on (user_id, type). Re-consenting after a policy
     * change, and withdrawing and re-granting marketing, are both normal and
     * both have to leave the earlier rows standing.
     */
    public function up(): void
    {
        Schema::create('consent_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('type', 32)->comment('App\Modules\Privacy\Enums\ConsentType');

            /*
             * False is a real, meaningful row: it is how a withdrawal is
             * recorded, and how a refused optional consent is distinguished
             * from one that was never put to the person at all.
             */
            $table->boolean('granted');

            $table->unsignedInteger('document_version')->nullable()
                ->comment('The content page version on screen when this was agreed.');
            $table->string('document_slug', 64)->nullable();

            /*
             * Where the consent was collected: "registration", "checkout",
             * "settings", "api". A consent whose origin is unknown is hard to
             * defend as freely given.
             */
            $table->string('source', 32);

            /* Who agreed, from where. What makes this evidence rather than a flag. */
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->nullable();

            /* The query the registry actually runs: newest row per user and type. */
            $table->index(['user_id', 'type', 'recorded_at']);
        });

        AppendOnlyTable::protect('consent_records');
    }

    public function down(): void
    {
        AppendOnlyTable::unprotect('consent_records');

        Schema::dropIfExists('consent_records');
    }
};
