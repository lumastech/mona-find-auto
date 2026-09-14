<?php

declare(strict_types=1);

use App\Modules\Inventory\Enums\StockImportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One bulk stock upload, and what the platform made of it.
     *
     * The row exists between the upload and the apply, which is the whole
     * point of it: the seller sees a per-row report first and decides. The
     * report is stored rather than recomputed because the file it describes
     * is deleted once the batch is applied — a seller's price list is not
     * something to keep lying around on disk.
     *
     * There is no external system to sync with. A seller's stock lives here.
     */
    public function up(): void
    {
        Schema::create('stock_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('original_filename');
            $table->string('stored_path')->nullable()
                ->comment('On the private disk, and removed once the batch is finished.');
            $table->string('status', 32)->default(StockImportStatus::AwaitingConfirmation->value);

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('applied_rows')->default(0);

            $table->json('rows')->nullable()
                ->comment('The parsed rows with their per-row errors: the report the seller reads.');
            $table->text('failure_reason')->nullable()
                ->comment('Why the file could not be read at all, when it could not.');

            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_import_batches');
    }
};
