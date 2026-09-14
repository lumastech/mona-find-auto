<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Modules\Identity\Database\Factories\PhoneVerificationFactory;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Support\PhoneNumberCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-time code issued over SMS.
 *
 * Issue and check these through OtpService — it owns the expiry, attempt and
 * resend rules that make a six-digit code safe to use.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $phone
 * @property OtpPurpose $purpose
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $last_sent_at
 * @property string|null $request_ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class PhoneVerification extends Model
{
    /** @use HasFactory<PhoneVerificationFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'phone' => PhoneNumberCast::class,
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Codes that have not been used up yet, newest first.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOutstanding(Builder $query, string $phone, OtpPurpose $purpose): void
    {
        $query->where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function hasAttemptsLeft(int $maximum): bool
    {
        return $this->attempts < $maximum;
    }

    /**
     * Seconds still to wait before another code may be sent to this number.
     */
    public function secondsUntilResend(int $throttleSeconds): int
    {
        if ($this->last_sent_at === null) {
            return 0;
        }

        $readyAt = $this->last_sent_at->addSeconds($throttleSeconds);

        return $readyAt->isFuture() ? (int) ceil(now()->diffInSeconds($readyAt, absolute: true)) : 0;
    }
}
