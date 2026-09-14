<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Payments\Database\Factories\PayoutLineFactory;
use App\Modules\Payments\Enums\PayoutLineStatus;
use App\Modules\Sellers\Enums\PayoutMethod;
use App\Modules\Sellers\Models\PayoutAccount;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One seller's share of a payout batch, succeeding or failing on its own.
 *
 * @property int $id
 * @property int $payout_batch_id
 * @property int $seller_id
 * @property int|null $payout_account_id
 * @property string $reference
 * @property PayoutLineStatus $status
 * @property Money $amount_ngwee
 * @property Money|null $fee_ngwee
 * @property PayoutMethod|null $method
 * @property string|null $lenco_transfer_id
 * @property string|null $lenco_recipient_id
 * @property string|null $resolved_name_at_payout
 * @property string|null $block_reason
 * @property string|null $failure_reason
 * @property int|null $journal_entry_id
 * @property Carbon|null $sent_at
 * @property Carbon|null $settled_at
 * @property Carbon|null $failed_at
 * @property-read Seller $seller
 * @property-read PayoutBatch $batch
 * @property-read PayoutAccount|null $account
 */
class PayoutLine extends Model
{
    /** @use HasFactory<PayoutLineFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PayoutLineStatus::class,
            'method' => PayoutMethod::class,
            'amount_ngwee' => MoneyCast::class,
            'fee_ngwee' => MoneyCast::class,
            'sent_at' => 'datetime',
            'settled_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PayoutBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }

    /**
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * @return BelongsTo<PayoutAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class, 'payout_account_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * The reference a transfer for this line carries at the gateway.
     *
     * Batch and seller rather than a random string, so that a Lenco dashboard
     * row can be traced back to a run and a shop without a database lookup —
     * which is exactly what someone is doing when a payout is queried.
     */
    public static function referenceFor(int $batchId, int $sellerId): string
    {
        return sprintf('PO-%d-%d', $batchId, $sellerId);
    }

    /**
     * The recipient payload the gateway needs to address this transfer.
     *
     * @return array<string, string>
     */
    public function recipientPayload(): array
    {
        $account = $this->account;

        if ($account === null) {
            return [];
        }

        $common = array_filter([
            'recipient_id' => $this->lenco_recipient_id ?? $account->lenco_recipient_id,
            'narration' => 'MonaFind payout '.$this->reference,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        return $account->method->isBank()
            ? $common + array_filter([
                'account_number' => $account->account_number,
                'bank_code' => $account->bank_code,
                'account_name' => $account->resolved_name,
            ], static fn (?string $value): bool => $value !== null && $value !== '')
            : $common + array_filter([
                'phone' => $account->mobile_number,
                'network' => $account->network?->value,
            ], static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
