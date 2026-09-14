<?php

declare(strict_types=1);

namespace App\Modules\Finance\Jobs;

use App\Modules\Finance\Services\SellerStatementService;
use App\Modules\Sellers\Models\Seller;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Closes last month for every seller who traded in it.
 *
 * Runs on the fourth of the month rather than the first, deliberately. A
 * payout initiated on the last day of the month may confirm a day or two
 * later, and a statement closed at one minute past midnight would report it
 * as unpaid and then never change its mind — the row is written once and read
 * forever after. Three days is comfortably longer than a Lenco transfer takes
 * to settle and short enough that sellers get their figures in the first week.
 *
 * Sellers are asked of the LEDGER, not of the seller table: a shop that did
 * not trade gets no statement at all, rather than a page of zeroes to
 * download every month.
 *
 * One seller failing does not stop the rest. Statements are independent
 * documents and a single bad row should cost one seller their statement, not
 * the whole platform's — the failure is logged with the seller on it so the
 * month can be re-run for that one alone.
 */
class GenerateMonthlyStatements implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly ?string $month = null) {}

    public function handle(SellerStatementService $statements): void
    {
        $month = $this->monthToClose();
        $sellerIds = $statements->sellersWithActivityIn($month);

        if ($sellerIds === []) {
            return;
        }

        Seller::query()
            ->whereIn('id', $sellerIds)
            ->eachById(function (Seller $seller) use ($statements, $month): void {
                try {
                    $statements->generate($seller, $month);
                } catch (Throwable $exception) {
                    Log::error('Could not close the month for a seller.', [
                        'seller_id' => $seller->getKey(),
                        'month' => $month->format('Y-m'),
                        'exception' => $exception->getMessage(),
                    ]);
                }
            });
    }

    /**
     * The month this run closes: the one named, or the one just gone.
     */
    private function monthToClose(): CarbonInterface
    {
        if ($this->month !== null) {
            return CarbonImmutable::parse($this->month)->startOfMonth();
        }

        return CarbonImmutable::now(
            (string) config('monafind.display_timezone', 'Africa/Lusaka'),
        )->subMonth()->startOfMonth();
    }
}
