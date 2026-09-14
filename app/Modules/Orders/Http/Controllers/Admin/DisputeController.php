<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Orders\Enums\DisputeResolution;
use App\Modules\Orders\Enums\DisputeStatus;
use App\Modules\Orders\Exceptions\DisputeNotAllowed;
use App\Modules\Orders\Http\Requests\Admin\ResolveDisputeRequest;
use App\Modules\Orders\Http\Resources\DisputeResource;
use App\Modules\Orders\Models\OrderDispute;
use App\Modules\Orders\Services\DisputeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dispute queue.
 *
 * Every row here is an order that will not complete and money that will not
 * move until somebody decides. It is sorted oldest first and opens on the
 * unresolved ones, because a queue that opens on the archive is a queue
 * nobody works.
 *
 * Resolving is the only write, and it is the instruction Payments acts on.
 * Nothing in this controller touches the ledger itself — it records the
 * decision and fires DisputeResolved, and Prompt 09's listeners do the rest.
 */
class DisputeController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly DisputeService $disputes) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string'],
        ]);

        $status = DisputeStatus::tryFrom($filters['status'] ?? '');

        $disputes = OrderDispute::query()
            ->with(['order.seller', 'order.buyer', 'opener', 'resolver', 'media'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($status === null, fn ($query) => $query->open())
            ->oldest('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (OrderDispute $dispute): array => DisputeResource::make($dispute)->resolve($request));

        return Inertia::render('admin/disputes/Index', [
            'disputes' => $disputes,
            'filters' => ['status' => $status?->value],
            'statuses' => array_map(
                static fn (DisputeStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                DisputeStatus::cases(),
            ),
            'resolutions' => DisputeResolution::options(),
        ]);
    }

    public function show(Request $request, OrderDispute $dispute): Response
    {
        Gate::authorize('view', $dispute);

        $dispute->load(['order.items', 'order.seller.city', 'order.buyer', 'order.statusEvents.actor', 'opener', 'resolver', 'media']);

        return Inertia::render('admin/disputes/Show', [
            'dispute' => DisputeResource::make($dispute)->resolve($request),
            'timeline' => $dispute->order->statusEvents
                ->map(static fn ($event): array => [
                    'status' => $event->to_status->value,
                    'headline' => $event->headline(),
                    'actor' => $event->actorName(),
                    'reason' => $event->reason,
                    'at' => $event->created_at->toIso8601String(),
                ])
                ->all(),
            'resolutions' => DisputeResolution::options(),
        ]);
    }

    /**
     * A moderator takes the dispute on, so the queue shows it is being worked.
     */
    public function claim(Request $request, OrderDispute $dispute): RedirectResponse
    {
        Gate::authorize('resolve', $dispute);

        try {
            $this->disputes->claim($dispute, $this->currentUser($request));
        } catch (DisputeNotAllowed $exception) {
            return back()->withErrors(['dispute' => $exception->getMessage()]);
        }

        return back();
    }

    public function resolve(ResolveDisputeRequest $request, OrderDispute $dispute): RedirectResponse
    {
        Gate::authorize('resolve', $dispute);

        try {
            $this->disputes->resolve(
                $dispute,
                $request->resolution(),
                $this->currentUser($request),
                $request->refundAmount(),
                (string) $request->validated('note'),
            );
        } catch (DisputeNotAllowed $exception) {
            return back()->withErrors(['dispute' => $exception->getMessage()]);
        }

        return to_route('admin.disputes.index')->with('toast', [
            'type' => 'success',
            'message' => __('Dispute resolved. Both parties have been told.'),
        ]);
    }
}
