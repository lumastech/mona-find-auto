<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vehicle manufacturers.
     *
     * Reference data: staff curate the list, sellers pick from it. Free text
     * would make "Toyota", "TOYOTA" and "Toyata" three different makes and
     * make the search facets useless, which is the whole reason this is a
     * table rather than a column.
     */
    public function up(): void
    {
        Schema::create('makes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('country', 60)->nullable()->comment('Where the marque is from, shown as a hint in pickers.');
            $table->boolean('is_popular')->default(false)
                ->comment('Sorted to the top of pickers. Most Zambian traffic is a handful of makes.');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true)
                ->comment('Retired makes stay for existing listings but leave the pickers.');
            $table->timestamps();

            $table->index(['is_active', 'is_popular', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('makes');
    }
};
