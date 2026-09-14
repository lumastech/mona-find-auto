<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Finance\Models\VatRate;
use App\Modules\Orders\Contracts\VatRateProvider;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The dated VAT schedule, and the only thing that writes to it.
 *
 * ## Why a schedule and not a setting
 *
 * The platform started with `monetisation.vat_on_commission_percent`, a
 * single number. That is enough right up until the rate changes, at which
 * point every question about the past gets the present answer: a statement
 * for March re-rendered in April would show April's rate against March's
 * commission and disagree with the invoice the seller already has.
 *
 * Snapshots are what actually protect settled orders — the rate is copied
 * onto the order at payment time and every downstream figure reads the copy.
 * This class protects the other half: knowing what the platform WOULD have
 * charged on a given day, which is what an administrator needs when a seller
 * queries an old invoice, and what a future-dated rate needs in order to be
 * enterable before it commences rather than at midnight on the day.
 *
 * ## Writing
 *
 * Adding a rate is a staff action against money, so it is audited like one.
 * The settings row is kept in step as a mirror so that anything still reading
 * the flat value — the settings screen, a deployment with Finance disabled —
 * sees today's rate rather than a stale one. The schedule is the truth; the
 * setting is a convenience that follows it.
 */
class VatRateSchedule implements VatRateProvider
{
    /**
     * The rate in force at a moment, as a decimal string.
     *
     * Falls back to the flat setting when the schedule has no row covering
     * the date. That happens in exactly two situations — a deployment whose
     * seeder has not run, and a query about a date before the platform
     * started keeping the schedule — and in both, the configured rate is a
     * better answer than zero.
     */
    public function percentAt(?CarbonInterface $moment = null): string
    {
        $rate = VatRate::inForceAt($moment ?? now());

        if ($rate !== null) {
            return $rate->rate_percent;
        }

        return (string) settings('monetisation.vat_on_commission_percent', '0.00');
    }

    /**
     * Put a rate on the schedule.
     *
     * A date already on the schedule is UPDATED rather than duplicated, which
     * is what lets an administrator correct a future rate they mistyped. The
     * audit row carries both figures, so a correction is still a matter of
     * record even though the row it replaced is gone.
     */
    public function schedule(string $percent, CarbonInterface $effectiveFrom, ?User $actor = null, ?string $note = null): VatRate
    {
        $date = $effectiveFrom->copy()->startOfDay();
        $existing = VatRate::query()->whereDate('effective_from', $date->toDateString())->first();

        $before = $existing === null ? null : [
            'rate_percent' => $existing->rate_percent,
            'effective_from' => $existing->effective_from->toDateString(),
        ];

        $rate = $existing ?? new VatRate;
        $rate->fill([
            'rate_percent' => $percent,
            'effective_from' => $date->toDateString(),
            'note' => $note,
            'created_by' => $actor?->getKey(),
        ])->save();

        $this->mirrorToSettings($actor);

        audit(
            $actor,
            $before === null ? 'vat_rate.scheduled' : 'vat_rate.amended',
            $rate,
            $before,
            ['rate_percent' => $rate->rate_percent, 'effective_from' => $rate->effective_from->toDateString()],
            $note ?? 'VAT on commission set to '.$percent.'% from '.$rate->effective_from->toDateString().'.',
        );

        return $rate->refresh();
    }

    /**
     * Take a rate off the schedule before it ever applied.
     *
     * Only a future rate can go. One whose day has come may have priced a
     * real order, and while deleting the row could not alter that order — the
     * figure lives in its snapshot — it would erase the platform's own record
     * of why it charged what it did.
     */
    public function withdraw(VatRate $rate, ?User $actor = null, ?string $reason = null): bool
    {
        if (! $rate->isWithdrawable()) {
            return false;
        }

        audit(
            $actor,
            'vat_rate.withdrawn',
            $rate,
            ['rate_percent' => $rate->rate_percent, 'effective_from' => $rate->effective_from->toDateString()],
            null,
            $reason ?? 'Scheduled VAT rate withdrawn before it took effect.',
        );

        $rate->delete();
        $this->mirrorToSettings($actor);

        return true;
    }

    /**
     * The whole schedule, newest first, with the current row flagged.
     *
     * @return Collection<int, array{
     *     id: int,
     *     ratePercent: string,
     *     effectiveFrom: string,
     *     note: string|null,
     *     author: string|null,
     *     isCurrent: bool,
     *     isScheduled: bool,
     *     isWithdrawable: bool
     * }>
     */
    public function timeline(): Collection
    {
        $current = VatRate::inForceAt(now());

        return VatRate::query()
            ->with('author')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (VatRate $rate): array => [
                'id' => (int) $rate->getKey(),
                'ratePercent' => $rate->rate_percent,
                'effectiveFrom' => $rate->effective_from->toDateString(),
                'note' => $rate->note,
                'author' => $rate->author?->name,
                'isCurrent' => $current !== null && $current->getKey() === $rate->getKey(),
                'isScheduled' => $rate->isWithdrawable(),
                'isWithdrawable' => $rate->isWithdrawable(),
            ]);
    }

    /**
     * Keep the flat setting pointing at today's rate.
     *
     * One-way, always: the schedule writes to the setting and never reads it
     * back as truth. Two places that both claim to hold the rate is one place
     * too many, and this is the cheap way to keep the older one honest
     * without hunting down every reader.
     */
    private function mirrorToSettings(?User $actor): void
    {
        $today = $this->percentAt(now());

        /* Nothing to mirror onto on a database whose settings seeder has not run. */
        if (! settings()->has('monetisation.vat_on_commission_percent')) {
            return;
        }

        if ((string) settings('monetisation.vat_on_commission_percent', '0.00') === $today) {
            return;
        }

        settings()->set(
            'monetisation.vat_on_commission_percent',
            $today,
            $actor,
            'Mirrored from the dated VAT schedule.',
        );
    }
}
