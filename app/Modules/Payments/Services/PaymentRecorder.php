<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Integrations\Payments\Data\CollectionResponse;
use App\Integrations\Payments\Data\PaymentStatus;
use App\Modules\Orders\Models\OrderGroup;
use App\Modules\Payments\Enums\PaymentChannel;
use App\Modules\Payments\Enums\PaymentSource;
use App\Modules\Payments\Models\Payment;
use App\Support\Money\Money;
use Illuminate\Database\QueryException;

/**
 * The only thing that writes a Payment row.
 *
 * ## What this class is really for
 *
 * A collection can be confirmed by three different routes at once — the
 * browser posting back to our verify endpoint, Lenco's webhook, and the
 * stuck-payment poller — and on a good day two of them arrive within the same
 * second. All three funnel through `record()`, which either writes the first
 * observation of that (reference, status) or discovers the database already
 * has one.
 *
 * The unique index does the deciding, not a `firstOrCreate`. Two queue
 * workers can both pass an existence check; only one can win an insert. That
 * is the whole reason the guarantee is worth anything.
 *
 * `record()` therefore returns null when the observation was already known,
 * and every caller reads that as "somebody else got here first, do nothing" —
 * which is what stops an order being settled twice, a ledger entry being
 * posted twice, or a buyer getting two receipts.
 */
class PaymentRecorder
{
    /**
     * Record what the gateway says, unless it has already been recorded.
     *
     * Returns the new row, or null if this exact observation already existed.
     */
    public function record(
        CollectionResponse $collection,
        PaymentSource $source,
        ?OrderGroup $group = null,
        ?int $attempt = null,
    ): ?Payment {
        $group ??= $this->groupFor($collection->reference);

        try {
            return Payment::create([
                'order_group_id' => $group?->getKey(),
                'user_id' => $group?->user_id,
                'reference' => $collection->reference,
                'attempt' => $attempt ?? $this->attemptFrom($collection->reference),
                'status' => $collection->status,
                'channel' => PaymentChannel::fromLenco($this->stringOrNull($collection->raw['type'] ?? null)),
                'bearer' => $this->stringOrNull($collection->raw['bearer'] ?? null),
                'amount_ngwee' => $this->amountFor($collection, $group),
                'fee_ngwee' => $collection->fee,
                'settled_ngwee' => $this->settledFrom($collection),
                'lenco_id' => $collection->gatewayId,
                'lenco_reference' => $this->stringOrNull($collection->raw['lencoReference'] ?? null),
                'failure_reason' => $collection->failureReason,
                'source' => $source,
                'raw' => $collection->raw !== [] ? $collection->raw : null,
                'observed_at' => now(),
            ]);
        } catch (QueryException $exception) {
            /*
             * A duplicate on (reference, status) is the expected outcome of a
             * webhook arriving twice, and is not an error — it is the
             * guarantee working. Anything else is a real database problem and
             * has to keep travelling.
             */
            if (! $this->isDuplicate($exception)) {
                throw $exception;
            }

            return null;
        }
    }

    /**
     * Note that an attempt has been started, before the buyer touches it.
     *
     * Gives the poller something to find if the buyer closes the tab and
     * nothing is ever heard again, and gives support a row to look at when a
     * buyer says they paid and no webhook arrived.
     */
    public function recordInitiated(OrderGroup $group, string $reference, int $attempt): ?Payment
    {
        return $this->record(
            new CollectionResponse(
                reference: $reference,
                status: PaymentStatus::Pending,
                amount: $group->total_ngwee,
            ),
            PaymentSource::Initiate,
            $group,
            $attempt,
        );
    }

    /**
     * The amount to record.
     *
     * Prefers what the gateway says it took, because that is the fact being
     * recorded. Falls back to the group's total only when the gateway gave no
     * figure at all — better a known expectation than a zero that would read
     * as a free order to every report downstream.
     */
    private function amountFor(CollectionResponse $collection, ?OrderGroup $group): Money
    {
        if ($collection->amount->isPositive()) {
            return $collection->amount;
        }

        return $group instanceof OrderGroup ? $group->total_ngwee : Money::zero();
    }

    /**
     * What Lenco settled, when the payload happens to carry a settlement.
     */
    private function settledFrom(CollectionResponse $collection): ?Money
    {
        $settlement = $collection->raw['settlement'] ?? null;

        if (! is_array($settlement) || ! isset($settlement['amountSettled'])) {
            return null;
        }

        return Money::ofKwacha((string) $settlement['amountSettled']);
    }

    /**
     * The group a reference belongs to, read back out of the reference.
     *
     * A webhook arrives with nothing but MFA-{publicId}-{attempt}, so the
     * reference has to be parseable — which is exactly why the format is
     * fixed in one place on the gateway.
     */
    private function groupFor(string $reference): ?OrderGroup
    {
        $publicId = $this->publicIdFrom($reference);

        return $publicId === null
            ? null
            : OrderGroup::query()->where('public_id', $publicId)->first();
    }

    private function publicIdFrom(string $reference): ?string
    {
        return preg_match('/^MFA-(.+)-(\d+)$/', $reference, $matches) === 1 ? $matches[1] : null;
    }

    private function attemptFrom(string $reference): int
    {
        return preg_match('/^MFA-(.+)-(\d+)$/', $reference, $matches) === 1 ? (int) $matches[2] : 1;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Whether a query failure was the unique index refusing a duplicate.
     *
     * SQLSTATE 23000 covers integrity violations on every driver the platform
     * runs on, which is why it is matched rather than a driver-specific code.
     */
    private function isDuplicate(QueryException $exception): bool
    {
        return $exception->getCode() === '23000'
            || str_contains($exception->getMessage(), 'UNIQUE constraint failed');
    }
}
