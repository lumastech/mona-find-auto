<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Ledger\Enums\LedgerAccountCode;
use App\Modules\Ledger\Enums\PostingRecipe;
use App\Modules\Ledger\Http\Resources\JournalEntryResource;
use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Ledger\Models\LedgerAccount;
use App\Modules\Ledger\Services\LedgerBalances;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The ledger browser.
 *
 * Read-only, and there is no other kind. Entries are append-only, so there is
 * nothing on this screen to edit and deliberately no button suggesting
 * otherwise — the one route by which a human moves money is a manual
 * adjustment, which lives on its own screen and needs two people.
 *
 * The account balances across the top are the cached figures, which is what
 * makes the page fast. The consistency between those and the lines beneath
 * them is checked by LedgerBalances::discrepancies() rather than assumed, and
 * the count is shown here so that a drift is visible to the person most
 * likely to care about it.
 */
class LedgerBrowserController extends Controller
{
    public function __construct(private readonly LedgerBalances $balances) {}

    public function index(Request $request): Response
    {
        Gate::authorize('view-ledger');

        $filters = $request->validate([
            'account' => ['nullable', 'string', 'max:64'],
            'recipe' => ['nullable', 'string', 'max:32'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $account = LedgerAccountCode::tryFrom($filters['account'] ?? '');
        $recipe = PostingRecipe::tryFrom($filters['recipe'] ?? '');

        $entries = JournalEntry::query()
            ->with(['lines.account', 'lines.subject', 'reference'])
            ->withRecipe($recipe)
            ->touchingAccount($account === null ? null : LedgerAccount::for($account))
            ->when(
                ($filters['search'] ?? null) !== null,
                fn ($query) => $query->where(function ($query) use ($filters): void {
                    $term = '%'.$filters['search'].'%';
                    $query->where('description', 'like', $term)
                        ->orWhere('idempotency_key', 'like', $term)
                        ->orWhere('uuid', 'like', $term);
                }),
            )
            ->latest('posted_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (JournalEntry $entry): array => JournalEntryResource::make($entry)->resolve($request));

        return Inertia::render('admin/finance/ledger/Index', [
            'entries' => $entries,
            'filters' => [
                'account' => $account?->value,
                'recipe' => $recipe?->value,
                'search' => $filters['search'] ?? null,
            ],
            'accounts' => $this->accountSummary(),
            'recipes' => PostingRecipe::options(),
            /*
             * Should always be zero. Surfaced rather than logged because a
             * cached balance that has drifted from its lines is exactly the
             * kind of thing that goes unnoticed until it is expensive.
             */
            'discrepancies' => count($this->balances->discrepancies()),
        ]);
    }

    public function show(Request $request, JournalEntry $entry): Response
    {
        Gate::authorize('view-ledger');

        $entry->load(['lines.account', 'lines.subject', 'reference', 'author']);

        return Inertia::render('admin/finance/ledger/Show', [
            'entry' => JournalEntryResource::make($entry)->resolve($request),
        ]);
    }

    /**
     * Every account with its current balance, in chart order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function accountSummary(): array
    {
        return array_map(fn (LedgerAccountCode $code): array => [
            'value' => $code->value,
            'label' => $code->label(),
            'type' => $code->type()->value,
            'description' => $code->description(),
            'balance_ngwee' => $this->balances->forAccount($code)->ngwee,
        ], LedgerAccountCode::cases());
    }
}
