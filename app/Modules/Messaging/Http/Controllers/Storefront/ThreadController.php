<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Storefront;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Messaging\Enums\ThreadSubjectType;
use App\Modules\Messaging\Exceptions\ThreadClosed;
use App\Modules\Messaging\Http\Requests\Storefront\OpenThreadRequest;
use App\Modules\Messaging\Http\Requests\Storefront\PostMessageRequest;
use App\Modules\Messaging\Http\Resources\MessageThreadResource;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Services\ThreadService;
use App\Modules\Messaging\Support\ThreadParties;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The buyer's and mechanic's inbox, and one conversation inside it.
 *
 * Shared between the two on purpose. A mechanic on MonaFind is a person with
 * a public profile who also buys parts, not a separate kind of account, and
 * giving them a second inbox would mean a message arriving in whichever of
 * the two they were not looking at. The seller portal has its own view of the
 * same threads because a shop's inbox belongs to the business rather than to
 * the person — see the Seller controller.
 *
 * Opening a thread is idempotent, so the "Message the seller" button on a
 * listing, an order or a quote can simply post here without first asking
 * whether a conversation already exists.
 */
class ThreadController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly ThreadService $threads) {}

    public function index(Request $request): Response
    {
        $user = $this->currentUser($request);

        $threads = MessageThread::query()
            ->forParticipant($user)
            ->with(['participants.user.seller', 'subject'])
            ->recentFirst()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (MessageThread $thread): array => MessageThreadResource::make($thread)->resolve($request));

        return Inertia::render('storefront/messages/Index', [
            'threads' => $threads,
        ]);
    }

    public function show(Request $request, MessageThread $thread): Response
    {
        Gate::authorize('view', $thread);

        $user = $this->currentUser($request);

        $thread->load(['participants.user.seller', 'messages.author.seller', 'messages.media', 'subject']);

        /* Opening a conversation is reading it. */
        $this->threads->markRead($thread, $user);

        return Inertia::render('storefront/messages/Show', [
            'thread' => MessageThreadResource::make($thread)->resolve($request),
        ]);
    }

    /**
     * Open (or find) the conversation about something, and land in it.
     */
    public function store(OpenThreadRequest $request): RedirectResponse
    {
        $user = $this->currentUser($request);

        $type = ThreadSubjectType::from((string) $request->validated('subject_type'));
        $subject = $type->find((int) $request->validated('subject_id'));

        if ($subject === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('That is no longer available.')]);

            return back();
        }

        $thread = $type->knowsItsParties()
            ? $this->threads->openFor($subject)
            : $this->threads->openWith($subject, ThreadParties::buyerAndSeller($user, $this->shopFor($subject)));

        /*
         * Authorised AFTER opening rather than before: for a quote or an
         * order the parties come off the row, so the only way to know whether
         * this person belongs in the conversation is to see the conversation.
         * An unauthorised caller has caused an empty thread to exist, which
         * costs a row and reveals nothing.
         */
        Gate::authorize('view', $thread);

        if (filled($request->validated('body'))) {
            try {
                $this->threads->post($thread, $user, (string) $request->validated('body'));
            } catch (ThreadClosed $exception) {
                Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
            }
        }

        return to_route('threads.show', $thread);
    }

    public function reply(PostMessageRequest $request, MessageThread $thread): RedirectResponse
    {
        Gate::authorize('reply', $thread);

        try {
            $this->threads->post(
                thread: $thread,
                author: $this->currentUser($request),
                body: (string) $request->validated('body'),
                attachments: $request->file('attachments') ?? [],
            );
        } catch (ThreadClosed $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return back();
    }

    public function markRead(Request $request, MessageThread $thread): RedirectResponse
    {
        Gate::authorize('markRead', $thread);

        $this->threads->markRead($thread, $this->currentUser($request));

        return back();
    }

    /**
     * The shop a listing or shopfront belongs to.
     *
     * Only reached for the two subject types whose parties are NOT known from
     * the subject alone (ThreadSubjectType::knowsItsParties()), which is why
     * anything else here is a wiring bug rather than a user's mistake.
     */
    private function shopFor(Model $subject): Seller
    {
        return match (true) {
            $subject instanceof Seller => $subject,
            $subject instanceof Product => $subject->seller,
            default => throw new RuntimeException(
                sprintf('A %s conversation has no shop to address.', $subject::class),
            ),
        };
    }
}
