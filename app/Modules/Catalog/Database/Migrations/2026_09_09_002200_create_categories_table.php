<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The part category tree: Engine → Fuel system → Injectors.
     *
     * Stored as a parent pointer plus a materialised path, so "everything
     * under Engine" is one indexed LIKE rather than a recursive walk, and a
     * breadcrumb is read straight off the row. `depth` and `path` are derived
     * — App\Modules\Catalog\Services\CategoryTree owns them and nothing else
     * writes them.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            /*
             * Derived from the parent chain. depth 0 is a root; path holds
             * every ancestor id and the row's own, delimited both ends, so
             * "/3/" matches the subtree of category 3 and nothing else.
             */
            $table->unsignedTinyInteger('depth')->default(0);
            $table->string('path', 255)->default('')->comment('Materialised ancestor path, e.g. "/1/7/23/".');

            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['parent_id', 'position']);
            $table->index('path');
            $table->index(['is_active', 'depth']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
