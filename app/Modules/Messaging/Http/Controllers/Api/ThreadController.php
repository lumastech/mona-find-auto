<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Api;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Messaging\Enums\ThreadSubjectType;
use App\Modules\Messaging\Exceptions\ThreadClosed;
use App\Modules\Messaging\Http\Requests\Storefront\OpenThreadRequest;
use App\Modules\Messaging\Http\Requests\Storefront\PostMessageRequest;
use App\Modules\Messaging\Http\Resources\MessageResource;
use App\Modules\Messaging\Http\Resources\MessageThreadResource;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Services\ThreadService;
use App\Modules\Messaging\Support\ThreadParties;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * /api/v1/threads — conversations, for the mobile app.
 *
 * One controller for both sides of a conversation, as with quotations: which
 * threads come back depends on who is asking, and `?role=seller` asks for the
 * shop's inbox rather than the person's own.
 *
 * Authorisation is the policy's on every action, which means the mobile app
 * gets exactly the same rules as the web — including the one that lets a
 * moderator read a disputed order's thread and nobody else read anything.
 */
class ThreadController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly ThreadService $threads) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $filters = $request->validate([
            'role' => ['nullable', 'in:buyer,seller'],
            'unread' => ['nullable', 'boolean'],
        ]);

        $shop = ($filters['role'] ?? 'buyer') === 'seller' ? $user->seller : null;

        if (($filters['role'] ?? null) === 'seller' && $shop === null) {
            return ApiResponse::error(
                'not_a_seller',
                'This account does not run a shop on MonaFind.',
                status: HttpResponse::HTTP_FORBIDDEN,
            );
        }

        $threads = MessageThread::query()
            ->when(
                $shop,
                fn ($query, Seller $shop) => $query->forSeller($shop),
                fn ($query) => $query->forParticipant($user),
            )
            ->with(['participants.user.seller', 'subject'])
            ->recentFirst()
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 20))))
            ->withQueryString()
            ->through(fn (MessageThread $thread): array => MessageThreadResource::make($thread)->resolve($request));

        return ApiResponse::paginated($threads, [
            'unread_count' => $this->threads->unreadCountFor($user),
        ]);
    }

    public function show(Request $request, MessageThread $thread): JsonResponse
    {
        Gate::authorize('view', $thread);

        $thread->load(['participants.user.seller', 'messages.author.seller', 'messages.media', 'subject']);

        /* Fetching a conversation is reading it; a no-op for a moderator. */
        $this->threads->markRead($thread, $this->currentUser($request));

        return ApiResponse::ok(MessageThreadResource::make($thread)->resolve($request));
    }

    /**
     * Open (or find) the conversation about something.
     */
    public function store(OpenThreadRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $type = ThreadSubjectType::from((string) $request->validated('subject_type'));
        $subject = $type->find((int) $request->validated('subject_id'));

        if ($subject === null) {
            return ApiResponse::error(
                'subject_not_found',
                'That listing, quote or order no longer exists.',
                status: HttpResponse::HTTP_NOT_FOUND,
            );
        }

        $thread = $type->knowsItsParties()
            ? $this->threads->openFor($subject)
            : $this->threads->openWith($subject, ThreadParties::buyerAndSeller($user, $this->shopFor($subject)));

        Gate::authorize('view', $thread);

        if (filled($request->validated('body'))) {
            $this->threads->post($thread, $user, (string) $request->validated('body'));
        }

        $thread->load(['participants.user.seller', 'messages.author.seller', 'messages.media', 'subject']);

        return ApiResponse::created(MessageThreadResource::make($thread)->resolve($request));
    }

    public function reply(PostMessageRequest $request, MessageThread $thread): JsonResponse
    {
        Gate::authorize('reply', $thread);

        try {
            $message = $this->threads->post(
                thread: $thread,
                author: $this->currentUser($request),
                body: (string) $request->validated('body'),
                attachments: $request->file('attachments') ?? [],
            );
        } catch (ThreadClosed $exception) {
            return ApiResponse::error(
                'thread_closed',
                $exception->getMessage(),
                status: HttpResponse::HTTP_CONFLICT,
            );
        }

        $message->load(['author.seller', 'media']);

        return ApiResponse::created(MessageResource::make($message)->resolve($request));
    }

    public function markRead(Request $request, MessageThread $thread): JsonResponse
    {
        Gate::authorize('markRead', $thread);

        $user = $this->currentUser($request);

        $this->threads->markRead($thread, $user);

        return ApiResponse::ok(['unread_count' => $this->threads->unreadCountFor($user)]);
    }

    /**
     * The shop a listing or shopfront belongs to.
     *
     * Only reached for the two subject types whose parties are NOT known from
     * the subject alone (ThreadSubjectType::knowsItsParties()), which is why
     * anything else here is a wiring bug rather than a caller's mistake.
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
