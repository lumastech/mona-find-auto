<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Modules\Ledger\Services\LedgerBalances;
use App\Modules\Payments\Models\PayoutBatch;
use App\Modules\Payments\Models\PayoutLine;
use App\Modules\Sellers\Concerns\ResolvesCurrentSeller;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What a seller is owed, what is being held, and when it arrives.
 *
 * ## Three numbers, and why all three are shown
 *
 * Payable is money waiting for the next run. Reserve is money that is theirs
 * but held back against disputes — direct-settlement sellers only. Escrow is
 * money from orders that have not completed yet, which is not theirs at all
 * so far as the ledger is concerned. Sellers otherwise add them up and
 * conclude the platform is short-changing them; showing the three separately
 * with the reason attached is cheaper than answering the question by email.
 */
class EarningsController extends Controller
{
    use ResolvesCurrentSeller;

    public function __construct(private readonly LedgerBalances $balances) {}

    public function index(Request $request): Response
    {
        $seller = $this->currentSeller($request);

        $payable = $this->balances->sellerPayable($seller);
        $reserve = $this->balances->sellerReserve($seller);

        $next = PayoutBatch::query()
            ->open()
            ->whereHas('lines', fn ($query) => $query->where('seller_id', $seller->getKey()))
            ->oldest('id')
            ->first();

        $lines = PayoutLine::query()
            ->with('batch')
            ->where('seller_id', $seller->getKey())
            ->latest('id')
            ->paginate(20);

        return Inertia::render('seller/earnings/Index', [
            'summary' => [
                'payableNgwee' => $payable->ngwee,
                'reserveNgwee' => $reserve->ngwee,
                'totalNgwee' => $payable->plus($reserve)->ngwee,
                'paymentMode' => $seller->payment_mode->value,
                'paymentModeLabel' => $seller->payment_mode->label(),
                'paymentModeDescription' => $seller->payment_mode->description(),
                'carriesReserve' => $seller->payment_mode->carriesReserve(),
                'reservePercent' => (float) settings('risk.reserve_percent', 10),
            ],
            'nextPayout' => $next === null ? null : [
                'reference' => $next->reference,
                'status' => $next->status->value,
                'statusLabel' => $next->status->label(),
                'scheduledFor' => $next->scheduled_for?->toDateString(),
            ],
            'payouts' => $lines->through(static fn (PayoutLine $line): array => [
                'reference' => $line->reference,
                'batchReference' => $line->batch->reference,
                'status' => $line->status->value,
                'statusLabel' => $line->status->label(),
                'amountNgwee' => $line->amount_ngwee->ngwee,
                'method' => $line->method?->value,
                /*
                 * The failure reason is shown, the block reason is not: a
                 * blocked line means the account name no longer matches, and
                 * that is a conversation for a person rather than a banner
                 * telling a seller their bank details look suspicious.
                 */
                'failureReason' => $line->failure_reason,
                'sentAt' => $line->sent_at?->toDateTimeString(),
                'settledAt' => $line->settled_at?->toDateTimeString(),
            ])->items(),
            'pagination' => [
                'currentPage' => $lines->currentPage(),
                'lastPage' => $lines->lastPage(),
                'total' => $lines->total(),
            ],
            'hasPayoutAccount' => $seller->payoutAccounts()->exists(),
            'minimumPayoutNgwee' => Money::ofNgwee((int) settings('payouts.minimum_ngwee', 5_000))->ngwee,
        ]);
    }
}
