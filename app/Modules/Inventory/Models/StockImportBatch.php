<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Inventory\Database\Factories\StockImportBatchFactory;
use App\Modules\Inventory\Enums\StockImportStatus;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One bulk stock upload.
 *
 * A batch exists between the upload and the apply so that a seller reads a
 * per-row report before anything touches their shelves. That gap is the
 * feature: a mistyped price in row 40 is something to be shown, not something
 * to be discovered from an angry buyer a week later.
 *
 * @phpstan-type ImportRow array{line: int, sku: string|null, quantity: int|null, price_ngwee: int|null, errors: array<int, string>, valid: bool}
 *
 * @property int $id
 * @property int $seller_id
 * @property int|null $actor_id
 * @property string $original_filename
 * @property string|null $stored_path
 * @property StockImportStatus $status
 * @property int $total_rows
 * @property int $valid_rows
 * @property int $invalid_rows
 * @property int $applied_rows
 * @property list<ImportRow>|null $rows
 * @property string|null $failure_reason
 * @property Carbon|null $applied_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Seller $seller
 * @property-read User|null $actor
 */
class StockImportBatch extends Model
{
    /** @use HasFactory<StockImportBatchFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StockImportStatus::class,
            'rows' => 'array',
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'invalid_rows' => 'integer',
            'applied_rows' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The rows that will actually be written if the seller applies this.
     *
     * @return list<ImportRow>
     */
    public function validRows(): array
    {
        return $this->rowsWhere(true);
    }

    /**
     * The rows the seller has to go and fix, with their reasons.
     *
     * @return list<ImportRow>
     */
    public function invalidRows(): array
    {
        return $this->rowsWhere(false);
    }

    /**
     * @return list<ImportRow>
     */
    private function rowsWhere(bool $valid): array
    {
        $rows = $this->rows ?? [];

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['valid'] === $valid,
        ));
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeAwaitingConfirmation(Builder $query): void
    {
        $query->where('status', StockImportStatus::AwaitingConfirmation);
    }
}
