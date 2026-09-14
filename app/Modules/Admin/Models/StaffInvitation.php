<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Models\User;
use App\Modules\Admin\Database\Factories\StaffInvitationFactory;
use App\Modules\Admin\Enums\StaffInvitationStatus;
use App\Support\Roles\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An open offer of a staff role, sent to one address.
 *
 * @property int $id
 * @property string $email
 * @property string $name
 * @property string $role
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property int|null $invited_by
 * @property int|null $accepted_user_id
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $inviter
 */
class StaffInvitation extends Model
{
    /** @use HasFactory<StaffInvitationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function roleEnum(): ?Role
    {
        return Role::tryFrom($this->role);
    }

    /**
     * Derived, never stored — see the enum's docblock.
     */
    public function status(): StaffInvitationStatus
    {
        return match (true) {
            $this->accepted_at !== null => StaffInvitationStatus::Accepted,
            $this->revoked_at !== null => StaffInvitationStatus::Revoked,
            $this->expires_at->isPast() => StaffInvitationStatus::Expired,
            default => StaffInvitationStatus::Pending,
        };
    }

    public function isOpen(): bool
    {
        return $this->status() === StaffInvitationStatus::Pending;
    }

    /**
     * Invitations that could still be accepted.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }
}
