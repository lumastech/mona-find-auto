<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Admin;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Messaging\Enums\NotificationChannel;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Messaging\Http\Requests\Admin\NotificationMatrixRequest;
use App\Modules\Messaging\Services\NotificationMatrix;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The event→channel grid, for staff.
 *
 * The screen exists for one operational reason above all others: SMS costs
 * money per message and the bill arrives after the fact. Switching a row off
 * here stops those texts platform-wide, immediately, without a deployment.
 *
 * Mandatory rows are rendered as fixed. A verification code is not
 * configurable, and a grid that let somebody uncheck it would be a grid that
 * locked every account out of the platform between a click and a deploy.
 */
class NotificationMatrixController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly NotificationMatrix $matrix) {}

    public function edit(Request $request): Response
    {
        Gate::authorize('admin-only');

        return Inertia::render('admin/notifications/Matrix', [
            'matrix' => $this->matrix->toArray(),
            'defaults' => NotificationMatrix::defaults(),

            'channels' => array_map(
                static fn (NotificationChannel $channel): array => [
                    'value' => $channel->value,
                    'label' => $channel->label(),
                    'description' => $channel->description(),
                ],
                NotificationChannel::cases(),
            ),

            'groups' => array_map(
                static fn (string $group, array $events): array => [
                    'group' => $group,
                    'events' => array_map(
                        static fn (NotificationEvent $event): array => [
                            'event' => $event->value,
                            'label' => $event->label(),
                            'description' => $event->description(),
                            'mandatory' => $event->isMandatory(),
                        ],
                        $events,
                    ),
                ],
                array_keys(NotificationEvent::grouped()),
                array_values(NotificationEvent::grouped()),
            ),
        ]);
    }

    public function update(NotificationMatrixRequest $request): RedirectResponse
    {
        Gate::authorize('admin-only');

        $this->matrix->replace(
            $request->validated('matrix'),
            $this->currentUser($request),
            $request->validated('reason'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Notification routing updated.'),
        ]);

        return back();
    }
}
