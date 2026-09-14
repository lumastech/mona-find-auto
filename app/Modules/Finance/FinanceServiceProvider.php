<?php

declare(strict_types=1);

namespace App\Modules\Finance;

use App\Modules\Finance\Jobs\GenerateMonthlyStatements;
use App\Modules\Finance\Services\CommissionInvoiceDocumentService;
use App\Modules\Finance\Services\FinanceExportService;
use App\Modules\Finance\Services\FinanceMetrics;
use App\Modules\Finance\Services\SellerStatementService;
use App\Modules\Finance\Services\StatementDocumentService;
use App\Modules\Finance\Services\VatRateSchedule;
use App\Modules\Orders\Contracts\VatRateProvider;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Schedule;

/**
 * Finance — the platform's own books, read back out.
 *
 * Everything in this module is downstream of Ledger and writes nothing to it.
 * Finance reads journal lines and renders them: as a dashboard, as a seller's
 * closed month, as a numbered commission invoice, as a file for an
 * accountant. The one thing it does write is the dated VAT schedule, and even
 * that reaches no order that has already been paid — the rate is snapshotted
 * onto an order at payment time and read from that copy forever after.
 *
 * The VatRateProvider binding is the module's only outward effect. Orders
 * declares the interface and ships a flat-setting floor; this replaces it with
 * a schedule that can answer "what was the rate on the 14th", which is the
 * question a seller querying an old invoice is actually asking.
 */
class FinanceServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        /*
         * The dated schedule replaces Orders' flat-setting floor. Bound as a
         * singleton because a single request can price several orders and
         * each would otherwise re-read the same schedule.
         */
        $this->app->singleton(VatRateSchedule::class);
        $this->app->bind(VatRateProvider::class, VatRateSchedule::class);

        $this->app->singleton(FinanceMetrics::class);
        $this->app->singleton(SellerStatementService::class);
        $this->app->singleton(StatementDocumentService::class);
        $this->app->singleton(CommissionInvoiceDocumentService::class);
        $this->app->singleton(FinanceExportService::class);
    }

    protected function bootModule(): void
    {
        $this->registerSchedule();
    }

    /**
     * Monthly, on the fourth, not the first.
     *
     * A payout initiated on the last day of the month can confirm a day or
     * two later, and a statement closed at one minute past midnight would
     * report it as unpaid and then never change its mind — the row is written
     * once and read forever after. Three days clears a Lenco transfer
     * comfortably and still gets sellers their figures in the first week.
     */
    private function registerSchedule(): void
    {
        Schedule::job(new GenerateMonthlyStatements)
            ->monthlyOn(4, '03:30')
            ->name('finance:generate-monthly-statements')
            ->withoutOverlapping();
    }
}
