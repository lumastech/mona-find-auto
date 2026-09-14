<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Models\User;
use App\Modules\Catalog\Database\Factories\ListingReviewEventFactory;
use App\Modules\Catalog\Enums\ListingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One move in a listing's moderation history.
 *
 * The audit log records that a decision happened; this records what the
 * seller was told, field by field, so a re-submission can be read against the
 * reasons it was turned down for. Sellers see `reason` and `field_reasons`;
 * `note` is the moderator's own and stays inside the console.
 *
 * @property int $id
 * @property int $product_id
 * @property ListingStatus $from_status
 * @property ListingStatus $to_status
 * @property string|null $reason
 * @property array<string, string>|null $field_reasons
 * @property string|null $note
 * @property int|null $actor_id
 * @property Carbon|null $created_at
 * @property-read Product $product
 * @property-read User|null $actor
 */
class ListingReviewEvent extends Model
{
    /** @use HasFactory<ListingReviewEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => ListingStatus::class,
            'to_status' => ListingStatus::class,
            'field_reasons' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * One line for the history list: "Published by Chanda Mwale".
     */
    public function summary(): string
    {
        /* No actor means the platform did it — an auto-unpublish, say. */
        return $this->to_status->label().' by '.($this->actor->name ?? 'MonaFind');
    }
}
