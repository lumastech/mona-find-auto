<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A mechanic's public profile, attached to an ordinary account.
 *
 * One profile per account, enforced by the unique index on user_id: a
 * mechanic is a buyer who also fixes cars, not a second login. That is why
 * this is a separate row rather than columns on `users` — the account exists
 * first, the profile is applied for later, and the profile is what gets
 * approved, suspended, filtered and rated.
 *
 * Contact details are held here rather than read off the account because the
 * number a mechanic wants work on is not always the one they signed up with,
 * and because a profile is a trading identity: publishing the account's
 * personal phone as a side effect of applying would be a surprise. They are
 * blurred for guests by Support\MechanicContact, server-side.
 *
 * Location is the same province/city pair the rest of the platform uses, so
 * the directory's filters and a seller's address speak the same language.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mechanic_profiles', function (Blueprint $table): void {
            $table->id();

            /* One profile per account. */
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('display_name');
            $table->string('slug')->unique();
            $table->string('headline')->nullable()->comment('One line under the name.');
            $table->text('bio')->nullable();

            $table->string('qualification');
            $table->string('qualification_institution')->nullable();
            $table->unsignedSmallInteger('qualification_year')->nullable();
            $table->unsignedTinyInteger('years_experience')->default(0);

            $table->foreignId('province_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('street')->nullable();
            $table->string('plot_number')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            /* The number and address they want work on; not necessarily the account's. */
            $table->string('phone');
            $table->string('email')->nullable();

            $table->boolean('is_mobile')->default(false)->comment('Will travel to the vehicle.');
            $table->boolean('accepting_work')->default(true);

            $table->string('status', 24)->default('draft')->comment('MechanicStatus.');
            $table->text('review_note')->nullable()->comment('Staff note, not shown to the applicant.');
            $table->text('rejection_reason')->nullable()->comment('Shown to the applicant.');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /*
             * The directory query: approved profiles in a place, newest
             * first. Status leads because it is the filter that removes most
             * rows and is applied to every public read without exception.
             */
            $table->index(['status', 'province_id', 'city_id'], 'mechanic_profiles_directory_index');
            /* The staff queue. */
            $table->index(['status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mechanic_profiles');
    }
};
