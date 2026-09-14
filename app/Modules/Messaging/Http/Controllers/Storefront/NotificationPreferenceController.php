<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Messaging\Http\Requests\Storefront\NotificationPreferenceRequest;
use App\Modules\Messaging\Services\NotificationPreferenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Tell me about this, by this."
 *
 * Lives under /settings with the rest of the account screens rather than in
 * the seller portal, because one person may run a shop, hold a mechanic
 * profile and buy parts, and their preferences are theirs rather than their
 * shop's.
 *
 * The screen shows mandatory events as fixed and matrix-disabled channels as
 * unavailable. Showing a switch that does nothing is worse than showing none:
 * somebody turns off verification codes, believes they have, and is then
 * surprised by one.
 */
class NotificationPreferenceController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly NotificationPreferenceService $preferences) {}

    public function edit(Request $request): Response
    {
        $user = $this->currentUser($request);

        return Inertia::render('settings/Notifications', [
            'groups' => $this->preferences->screenFor($user),

            /*
             * So the screen can explain WHY an SMS row is greyed out for this
             * person, rather than leaving them to wonder.
             */
            'reachable' => [
                'sms' => filled($user->phone) && $user->phone_verified_at !== null,
                'mail' => filled($user->email),
            ],
        ]);
    }

    public function update(NotificationPreferenceRequest $request): RedirectResponse
    {
        $this->preferences->update(
            $this->currentUser($request),
            $request->validated('preferences'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Your notification preferences have been saved.'),
        ]);

        return back();
    }
}
