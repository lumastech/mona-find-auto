<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Http\Requests\Admin\ScheduleVatRateRequest;
use App\Modules\Finance\Models\VatRate;
use App\Modules\Finance\Services\VatRateSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The VAT rate MonaFind charges on its commission, and when each one started.
 *
 * Reading is a finance job; changing a tax rate is a platform-administrator
 * one, which is why the two gates differ. A rate is the single figure on the
 * platform that is not MonaFind's to choose.
 *
 * Nothing on this screen can reach an order that has already been paid. The
 * rate is copied onto the order at payment time and every invoice, statement
 * and refund figure is derived from that copy — so the worst a mistake here
 * can do is misprice FUTURE orders, which is recoverable. The screen says so,
 * because an administrator who believes otherwise will be afraid to correct a
 * typo.
 */
class VatRateController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly VatRateSchedule $schedule) {}

    public function index(Request $request): Response
    {
        Gate::authorize('finance');

        return Inertia::render('admin/finance/vat/Index', [
            'rates' => $this->schedule->timeline(),
            'currentPercent' => $this->schedule->percentAt(),
            'canManage' => Gate::allows('admin-only'),
        ]);
    }

    public function store(ScheduleVatRateRequest $request): RedirectResponse
    {
        $rate = $this->schedule->schedule(
            percent: $request->string('rate_percent')->toString(),
            effectiveFrom: CarbonImmutable::parse($request->string('effective_from')->toString()),
            actor: $this->currentUser($request),
            note: $request->input('note'),
        );

        return back()->with('success', __('VAT on commission set to :rate% from :date.', [
            'rate' => $rate->rate_percent,
            'date' => $rate->effective_from->format('j M Y'),
        ]));
    }

    /**
     * Withdraw a rate that has not started applying yet.
     *
     * A rate whose day has come is not withdrawable, and the service — not
     * this controller — is what decides that, so the rule holds however the
     * request arrives.
     */
    public function destroy(Request $request, VatRate $rate): RedirectResponse
    {
        Gate::authorize('admin-only');

        $withdrawn = $this->schedule->withdraw(
            $rate,
            $this->currentUser($request),
            $request->input('reason'),
        );

        return $withdrawn
            ? back()->with('success', __('Scheduled rate withdrawn.'))
            : back()->with('error', __('That rate has already taken effect and cannot be withdrawn.'));
    }
}
