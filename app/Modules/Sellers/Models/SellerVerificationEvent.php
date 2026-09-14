<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Models;

use App\Models\User;
use App\Modules\Sellers\Database\Factories\SellerVerificationEventFactory;
use App\Modules\Sellers\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One move of a seller's application through the verification workflow.
 *
 * @property int $id
 * @property int $seller_id
 * @property VerificationStatus|null $from_status
 * @property VerificationStatus $to_status
 * @property string|null $note
 * @property string|null $reason
 * @property array<string, bool>|null $checklist
 * @property int|null $actor_id
 * @property Carbon $created_at
 * @property-read Seller $seller
 * @property-read User|null $actor
 */
class SellerVerificationEvent extends Model
{
    /** @use HasFactory<SellerVerificationEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => VerificationStatus::class,
            'to_status' => VerificationStatus::class,
            'checklist' => 'array',
            'created_at' => 'datetime',
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
     * How the move reads in a history list.
     */
    public function summary(): string
    {
        return $this->from_status === null
            ? $this->to_status->label()
            : sprintf('%s → %s', $this->from_status->label(), $this->to_status->label());
    }
}
