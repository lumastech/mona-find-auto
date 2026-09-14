<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Http\Controllers\Api;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Privacy\Http\Requests\RequestErasureRequest;
use App\Modules\Privacy\Http\Requests\UpdateConsentRequest;
use App\Modules\Privacy\Models\ErasureRequest;
use App\Modules\Privacy\Services\ConsentRecorder;
use App\Modules\Privacy\Services\ErasureGuard;
use App\Modules\Privacy\Services\ErasureRequestService;
use App\Modules\Privacy\Services\PersonalDataExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The same rights, for the mobile app.
 *
 * A person's data-protection rights cannot depend on which client they happen
 * to be holding, so every action on the web settings screen has an endpoint
 * here and both go through the same services.
 *
 * The export comes back as JSON inside the envelope rather than as a file
 * download — an app wants the structure, and can render or save it itself.
 * The PDF has no equivalent here for the same reason.
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

    /**
     * Where this account stands: consents, any open erasure, anything holding
     * one up.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $pending = $this->erasures->openRequestFor($user);

        return ApiResponse::ok([
            'consents' => $this->consents->summaryFor($user),
            'erasure' => $pending === null ? null : [
                'id' => $pending->getKey(),
                'status' => $pending->status->value,
                'erase_after' => $pending->erase_after->toIso8601String(),
                'blocked_reason' => $pending->blocked_reason,
                'cancellable' => $pending->status->isCancellable(),
            ],
            'blockers' => $this->guard->blockersFor($user),
        ]);
    }

    /**
     * Grant or withdraw an optional consent.
     */
    public function updateConsent(UpdateConsentRequest $request): JsonResponse
    {
        $record = $this->consents->record(
            $this->currentUser($request),
            $request->consentType(),
            $request->boolean('granted'),
            'api',
            $request,
        );

        return ApiResponse::ok([
            'type' => $record->type->value,
            'granted' => $record->granted,
            'recorded_at' => $record->recorded_at->toIso8601String(),
        ]);
    }

    /**
     * Everything MonaFind holds about the caller.
     */
    public function export(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        audit($user, 'privacy.export.downloaded', $user, null, ['format' => 'api']);

        return ApiResponse::ok($this->exporter->assemble($user));
    }

    /**
     * Ask to be erased.
     */
    public function requestErasure(RequestErasureRequest $request): JsonResponse
    {
        $erasure = $this->erasures->request($this->currentUser($request), $request->input('reason'));

        return ApiResponse::created([
            'id' => $erasure->getKey(),
            'status' => $erasure->status->value,
            'erase_after' => $erasure->erase_after->toIso8601String(),
        ]);
    }

    /**
     * Change your mind, inside the grace period.
     */
    public function cancelErasure(Request $request, ErasureRequest $erasureRequest): JsonResponse
    {
        Gate::authorize('cancel', $erasureRequest);

        $this->erasures->cancel($erasureRequest, $this->currentUser($request));

        return ApiResponse::ok([
            'id' => $erasureRequest->getKey(),
            'status' => $erasureRequest->status->value,
        ]);
    }
}
