<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Models;

use App\Models\User;
use App\Modules\Sellers\Database\Factories\SellerPolicyFactory;
use App\Modules\Sellers\Enums\PolicyType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One version of one of a seller's four policies.
 *
 * Rows are never edited once buyers can see them — SellerPolicyService writes
 * a new version instead — because an order records the version its buyer
 * accepted, and that text has to still say what it said at checkout.
 *
 * @property int $id
 * @property int $seller_id
 * @property PolicyType $type
 * @property int $version
 * @property string $body
 * @property Carbon $effective_from
 * @property bool $is_current
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Seller $seller
 */
class SellerPolicy extends Model
{
    /** @use HasFactory<SellerPolicyFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PolicyType::class,
            'version' => 'integer',
            'effective_from' => 'datetime',
            'is_current' => 'boolean',
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_current', true);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOfType(Builder $query, PolicyType $type): void
    {
        $query->where('type', $type);
    }

    /**
     * Whether this version is in force yet. A policy may be published with a
     * future effective date, in which case the previous version still governs
     * orders placed today.
     */
    public function isInForce(): bool
    {
        return $this->is_current && ! $this->effective_from->isFuture();
    }

    /**
     * A plain-text opening for listings and search results.
     */
    public function excerpt(int $characters = 160): string
    {
        return Str::limit(trim(strip_tags($this->body)), $characters);
    }
}
