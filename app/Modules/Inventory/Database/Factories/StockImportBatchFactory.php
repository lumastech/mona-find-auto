<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Database\Factories;

use App\Modules\Inventory\Enums\StockImportStatus;
use App\Modules\Inventory\Models\StockImportBatch;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Batches come out staged and waiting on the seller, which is the state every
 * interesting test starts from.
 *
 * @extends Factory<StockImportBatch>
 */
class StockImportBatchFactory extends Factory
{
    protected $model = StockImportBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_id' => Seller::factory(),
            'actor_id' => null,
            'original_filename' => 'stock.xlsx',
            'stored_path' => null,
            'status' => StockImportStatus::AwaitingConfirmation,
            'total_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'applied_rows' => 0,
            'rows' => [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function withRows(array $rows): static
    {
        $valid = count(array_filter($rows, static fn (array $row): bool => (bool) ($row['valid'] ?? false)));

        return $this->state([
            'rows' => $rows,
            'total_rows' => count($rows),
            'valid_rows' => $valid,
            'invalid_rows' => count($rows) - $valid,
        ]);
    }

    public function applied(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => StockImportStatus::Applied,
            'applied_rows' => $attributes['valid_rows'] ?? 0,
            'applied_at' => now(),
        ]);
    }
}
