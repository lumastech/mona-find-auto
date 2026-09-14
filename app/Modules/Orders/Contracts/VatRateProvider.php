<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use Carbon\CarbonInterface;

/**
 * What rate of VAT MonaFind charges on its own commission, on a given day.
 *
 * A rate is a fact with a date attached. ZRA changes it by statutory
 * instrument with a commencement date, and for a week either side of that
 * date the platform has to be able to answer "what was the rate on the 14th"
 * as well as "what is it now" — the first question being the one a seller
 * asks when they check an invoice against their own books.
 *
 * Declared here rather than in Finance because Orders is what needs the
 * answer: the rate is written into `orders.monetisation_snapshot` at payment
 * time and read from there forever after. Finance owns the schedule and binds
 * the real implementation; Orders ships a floor that reads the flat setting,
 * so an order is still priceable on a deployment where Finance is disabled.
 *
 * The percent is a decimal STRING ("16.00"), which is what Money::percentage()
 * parses exactly and what an administrator typed. A float here would put a
 * binary rounding error inside a tax figure.
 */
interface VatRateProvider
{
    /**
     * The rate in force on a given moment, as a decimal string.
     *
     * Defaults to now, which is the only thing a snapshot ever asks for —
     * the dated form exists for statements and for proving, after a change,
     * what an already-settled order was charged under.
     */
    public function percentAt(?CarbonInterface $moment = null): string;
}
