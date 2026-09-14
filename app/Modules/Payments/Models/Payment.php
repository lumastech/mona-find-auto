<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Integrations\Payments\Data\PaymentStatus;
use App\Models\User;
use App\Modules\Orders\Models\OrderGroup;
use App\Modules\Payments\Database\Factories\PaymentFactory;
use App\Modules\Payments\Enums\PaymentChannel;
use App\Modules\Payments\Enums\PaymentSource;
use App\Support\Database\AppendOnly;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One observation of one collection attempt.
 *
 * Append-only, so this is a log rather than a mutable record — see the
 * migration for why that shape was chosen and what it buys. The practical
 * consequence for callers: you never update a Payment, you `record()` a new
 * observation, and you read the current state with `currentFor()`.
 *
 * @property int $id
 * @property int|null $order_group_id
 * @property int|null $user_id
 * @property string $reference
 * @property int $attempt
 * @property PaymentStatus $status
 * @property PaymentChannel|null $channel
 * @property string|null $bearer
 * @property Money $amount_ngwee
 * @property Money|null $fee_ngwee
 * @property Money|null $settled_ngwee
 * @property string|null $lenco_id
 * @property string|null $lenco_reference
 * @property string|null $failure_reason
 * @property PaymentSource $source
 * @property array<string, mixed>|null $raw
 * @property Carbon $observed_at
 * @property Carbon|null $created_at
 * @property-read OrderGroup|null $orderGroup
 * @property-read User|null $buyer
 */
class Payment extends Model
{
    use AppendOnly;

    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $guarded = [];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'channel' => PaymentChannel::class,
            'source' => PaymentSource::class,
            'attempt' => 'integer',
            'amount_ngwee' => MoneyCast::class,
            'fee_ngwee' => MoneyCast::class,
            'settled_ngwee' => MoneyCast::class,
            'raw' => 'array',
            'observed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public static function booted(): void
    {
        static::creating(function (self $payment): void {
            /*
             * Stamped here rather than by Eloquent because the model has
             * timestamps off: observed_at is the moment the gateway was
             * believed, which is the only time that matters on a log row.
             */
            $payment->setAttribute('observed_at', $payment->observed_at ?? Carbon::now());
            $payment->setAttribute('created_at', $payment->created_at ?? Carbon::now());
        });
    }

    /**
     * @return BelongsTo<OrderGroup, $this>
     */
    public function orderGroup(): BelongsTo
    {
        return $this->belongsTo(OrderGroup::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The latest thing known about an attempt.
     *
     * Latest by id rather than by observed_at: two observations recorded in
     * the same second are ordered by insertion, and a webhook and a verify
     * call racing each other routinely land in the same second.
     */
    public static function currentFor(string $reference): ?self
    {
        return static::query()->where('reference', $reference)->orderByDesc('id')->first();
    }

    /**
     * Whether an attempt has been confirmed paid.
     *
     * Asks whether a successful row EXISTS rather than whether the latest one
     * is successful, so a stray late `failed` observation cannot make a
     * confirmed payment look unpaid.
     */
    public static function isSettled(string $reference): bool
    {
        return static::query()
            ->where('reference', $reference)
            ->where('status', PaymentStatus::Successful)
            ->exists();
    }

    /**
     * The successful attempt on a group, if there is one.
     */
    public static function settlementFor(OrderGroup $group): ?self
    {
        return static::query()
            ->where('order_group_id', $group->getKey())
            ->where('status', PaymentStatus::Successful)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Attempts that have been pending for longer than $minutes.
     *
     * Written as "has a pending row and no terminal row" rather than "latest
     * row is pending", because the poller's job is precisely to find attempts
     * nothing ever came back for.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeStuckPending(Builder $query, int $minutes): Builder
    {
        return $query
            ->where('status', PaymentStatus::Pending)
            ->where('observed_at', '<=', now()->subMinutes($minutes))
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('payments as terminal')
                    ->whereColumn('terminal.reference', 'payments.reference')
                    ->whereIn('terminal.status', [
                        PaymentStatus::Successful->value,
                        PaymentStatus::Failed->value,
                        PaymentStatus::Reversed->value,
                    ]);
            });
    }

    /**
     * The buyer's wallet or account, dug out of what the gateway told us.
     *
     * A refund goes back to where the money came from, and this is the only
     * record of where that was — the buyer's profile may say something else
     * entirely by the time a dispute is resolved.
     *
     * @return array{phone?: string, network?: string, account_number?: string, bank_code?: string}
     */
    public function destination(): array
    {
        $raw = $this->raw ?? [];

        /** @var array<string, mixed> $mobile */
        $mobile = is_array($raw['mobileMoneyDetails'] ?? null) ? $raw['mobileMoneyDetails'] : [];

        if ($mobile !== []) {
            return array_filter([
                'phone' => isset($mobile['phone']) ? (string) $mobile['phone'] : null,
                'network' => isset($mobile['operator']) ? (string) $mobile['operator'] : null,
            ], static fn (?string $value): bool => $value !== null && $value !== '');
        }

        /** @var array<string, mixed> $bank */
        $bank = is_array($raw['bankAccountDetails'] ?? null) ? $raw['bankAccountDetails'] : [];

        return array_filter([
            'account_number' => isset($bank['accountNumber']) ? (string) $bank['accountNumber'] : null,
            'bank_code' => isset($bank['bank']['id']) ? (string) $bank['bank']['id'] : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
