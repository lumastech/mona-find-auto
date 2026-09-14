<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Privacy\Http\Requests\RequestErasureRequest;
use App\Modules\Privacy\Http\Requests\UpdateConsentRequest;
use App\Modules\Privacy\Models\ErasureRequest;
use App\Modules\Privacy\Services\ConsentRecorder;
use App\Modules\Privacy\Services\ErasureGuard;
use App\Modules\Privacy\Services\ErasureRequestService;
use App\Modules\Privacy\Services\PersonalDataExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Your data" — the settings screen behind the rights the Act grants.
 *
 * One page, four things: what you have agreed to, a switch for the one
 * consent that is genuinely optional, a copy of everything we hold, and the
 * way out.
 *
 * Gathered on a single screen rather than scattered through settings because
 * a right nobody can find is not much of a right.
 */
class PrivacyController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        private readonly ConsentRecorder $consents,
        private readonly ErasureRequestService $erasures,
        private readonly ErasureGuard $guard,
        private readonly PersonalDataExporter $exporter,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->currentUser($request);
        $pending = $this->erasures->openRequestFor($user);

        return Inertia::render('settings/Privacy', [
            'consents' => $this->consents->summaryFor($user),
            'erasure' => $pending === null ? null : [
                'id' => $pending->getKey(),
                'status' => $pending->status->value,
                'status_label' => $pending->status->label(),
                'erase_after' => $pending->erase_after->toIso8601String(),
                'blocked_reason' => $pending->blocked_reason,
                'cancellable' => $pending->status->isCancellable(),
            ],
            /*
             * Shown before the person commits, not after. Somebody with an
             * order in flight should find that out on the button, not in an
             * email a fortnight later saying their deletion is on hold.
             */
            'blockers' => $this->guard->blockersFor($user),
            'graceDays' => (int) settings('privacy.erasure_grace_days', 14),
            'retentionUrl' => route('pages.show', 'privacy'),
        ]);
    }

    /**
     * Grant or withdraw an optional consent.
     *
     * Required consents are refused here: withdrawing the terms is not a
     * consent change, it is a request to close the account, and the form
     * offers that separately.
     */
    public function updateConsent(UpdateConsentRequest $request): RedirectResponse
    {
        $this->consents->record(
            $this->currentUser($request),
            $request->consentType(),
            $request->boolean('granted'),
            'settings',
            $request,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your preference has been saved.')]);

        return back();
    }

    /**
     * The machine-readable copy.
     *
     * Streamed as a download rather than rendered: it is somebody's whole
     * history on the platform and has no business in a browser tab, or in
     * the back/forward cache behind them.
     */
    public function downloadJson(Request $request): StreamedResponse
    {
        $user = $this->currentUser($request);

        audit($user, 'privacy.export.downloaded', $user, null, ['format' => 'json']);

        $json = $this->exporter->toJson($user);

        return response()->streamDownload(
            static function () use ($json): void {
                echo $json;
            },
            sprintf('monafind-data-%d-%s.json', $user->getKey(), now()->format('Y-m-d')),
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * The readable copy.
     */
    public function downloadPdf(Request $request): StreamedResponse
    {
        $user = $this->currentUser($request);

        audit($user, 'privacy.export.downloaded', $user, null, ['format' => 'pdf']);

        $pdf = $this->exporter->toPdf($user);

        return response()->streamDownload(
            static function () use ($pdf): void {
                echo $pdf;
            },
            sprintf('monafind-data-%d-%s.pdf', $user->getKey(), now()->format('Y-m-d')),
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Ask to be erased.
     */
    public function requestErasure(RequestErasureRequest $request): RedirectResponse
    {
        $this->erasures->request($this->currentUser($request), $request->input('reason'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Your account is scheduled for deletion. We have emailed you the details.'),
        ]);

        return back();
    }

    /**
     * Change your mind, inside the grace period.
     */
    public function cancelErasure(Request $request, ErasureRequest $erasureRequest): RedirectResponse
    {
        Gate::authorize('cancel', $erasureRequest);

        $this->erasures->cancel($erasureRequest, $this->currentUser($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your account will not be deleted.')]);

        return back();
    }
}
