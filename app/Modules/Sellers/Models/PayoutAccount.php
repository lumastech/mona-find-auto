<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Models;

use App\Modules\Identity\Enums\MobileNetwork;
use App\Modules\Sellers\Database\Factories\PayoutAccountFactory;
use App\Modules\Sellers\Enums\PayoutMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where a seller's money is sent.
 *
 * Every field that could be used to redirect a payout is encrypted at rest:
 * a leaked database dump must not hand anybody a list of account numbers.
 * The trade-off is that these columns cannot be searched or indexed, which
 * is why `last_four` and the bank code are kept in the clear — enough to
 * tell two accounts apart on screen and to route a transfer, useless on
 * their own to somebody who wants to redirect one.
 *
 * @property int $id
 * @property int $seller_id
 * @property PayoutMethod $method
 * @property string|null $label
 * @property string|null $beneficiary_name
 * @property string|null $account_number
 * @property string|null $bank_branch
 * @property string|null $bank_address
 * @property string|null $swift_code
 * @property string|null $tpin
 * @property string|null $mobile_number
 * @property string|null $bank_code
 * @property string|null $bank_name
 * @property MobileNetwork|null $network
 * @property string|null $last_four
 * @property string|null $resolved_name
 * @property string|null $lenco_recipient_id
 * @property Carbon|null $resolved_at
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Seller $seller
 */
class PayoutAccount extends Model
{
    /** @use HasFactory<PayoutAccountFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * The columns held as ciphertext. Listed here so a test can prove each
     * one is unreadable in the raw row.
     *
     * @var array<int, string>
     */
    public const ENCRYPTED_COLUMNS = [
        'beneficiary_name',
        'account_number',
        'bank_branch',
        'bank_address',
        'swift_code',
        'tpin',
        'mobile_number',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...array_fill_keys(self::ENCRYPTED_COLUMNS, 'encrypted'),
            'method' => PayoutMethod::class,
            'network' => MobileNetwork::class,
            'is_default' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Keep the clear-text tail in step with whichever number this account
     * holds, so the seller can tell two accounts apart without decrypting.
     */
    public static function booted(): void
    {
        static::saving(function (self $account): void {
            $number = $account->method->isBank() ? $account->account_number : $account->mobile_number;

            $account->last_four = is_string($number) && $number !== ''
                ? substr(preg_replace('/\D/', '', $number) ?: $number, -4)
                : null;
        });
    }

    /**
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeDefault(Builder $query): void
    {
        $query->where('is_default', true);
    }

    /**
     * Whether the gateway has confirmed this account exists and a payout can
     * be addressed to it. An unresolved account is never paid out to.
     */
    public function isResolved(): bool
    {
        return $this->lenco_recipient_id !== null && $this->resolved_at !== null;
    }

    /**
     * How the account reads on screen: never the full number, always enough
     * to recognise.
     */
    public function maskedNumber(): string
    {
        return $this->last_four === null ? '—' : '•••• '.$this->last_four;
    }

    /**
     * The one-line description used in pickers and payout logs.
     */
    public function displayName(): string
    {
        $institution = $this->method->isBank()
            ? ($this->bank_name ?? 'Bank')
            : ($this->network?->label() ?? 'Mobile money');

        return trim(($this->label ?? $institution).' '.$this->maskedNumber());
    }
}
