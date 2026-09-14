<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Models;

use App\Models\User;
use App\Modules\Mechanics\Database\Factories\MechanicEndorsementFactory;
use App\Modules\Mechanics\Enums\EndorsementStatus;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A shop vouching for a mechanic.
 *
 * The row outlives every decision made about it. Declining does not delete
 * it, revoking does not delete it, and asking again reuses it — so the badge
 * on a profile is a status test rather than a question about which of several
 * rows is the newest.
 *
 * It is also the thing a Seller→Mechanic rating hangs off, which is the
 * second reason it is never deleted: a review written while the endorsement
 * stood must keep the source that entitled it.
 *
 * @property int $id
 * @property int $mechanic_profile_id
 * @property int $seller_id
 * @property EndorsementStatus $status
 * @property string|null $message
 * @property int $requested_by
 * @property Carbon|null $requested_at
 * @property string|null $response_note
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon|null $endorsed_at
 * @property Carbon|null $revoked_at
 * @property string|null $revocation_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MechanicProfile $profile
 * @property-read Seller $seller
 * @property-read User $requester
 * @property-read User|null $decider
 */
class MechanicEndorsement extends Model
{
    /** @use HasFactory<MechanicEndorsementFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EndorsementStatus::class,
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'endorsed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MechanicProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(MechanicProfile::class, 'mechanic_profile_id');
    }

    /**
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * The mechanic's account, which asked.
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * The member of shop staff who answered — not the same person, and the
     * one a dispute about an endorsement asks about.
     *
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Whether this shop is the one being asked. The check every seller-portal
     * action is gated on: an endorsement is answerable only by its addressee.
     */
    public function isAddressedTo(Seller $seller): bool
    {
        return $this->seller_id === $seller->getKey();
    }

    /**
     * The badge text on a mechanic's profile.
     */
    public function badgeLabel(): string
    {
        return 'Endorsed by '.$this->seller->business_name;
    }

    /**
     * The ones that put a badge on a profile.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', EndorsementStatus::Endorsed);
    }

    /**
     * What a shop still owes an answer on, oldest first — a queue.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', EndorsementStatus::Requested)->oldest('requested_at');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForSeller(Builder $query, Seller $seller): void
    {
        $query->where('seller_id', $seller->getKey());
    }
}
