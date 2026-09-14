<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Services;

use App\Models\User;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Services\AccountModerationService;
use App\Modules\Privacy\Enums\ErasureStatus;
use App\Modules\Privacy\Events\AccountErased;
use App\Modules\Privacy\Models\ErasureRequest;
use App\Modules\Privacy\Notifications\ErasureCompletedNotification;
use App\Modules\Privacy\Support\Anonymiser;
use App\Modules\Privacy\Support\PersonalDataRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Carries out an erasure.
 *
 * ## The account row is anonymised, never deleted
 *
 * This is the single most important decision in the module, and it is forced
 * by the schema rather than chosen for convenience. Almost every table that
 * references `users` does so with `cascadeOnDelete`, orders and terms
 * acceptances among them. Deleting the row would take the platform's
 * financial and legal records with it — and the append-only triggers on
 * `terms_acceptances` would abort the statement half-way, leaving a failure
 * whose cause is three joins away from its symptom.
 *
 * So the row survives, holding tombstones from `Anonymiser`, with its id
 * intact. Every order, journal line, payment and audit entry still points at
 * a real account; none of them points at a person any more. The Act asks for
 * the data subject to become unidentifiable, not for referential integrity to
 * be destroyed, and those are different things.
 *
 * ## Order of operations
 *
 * Close, notify, then erase — in that order, and the order matters. Closing
 * revokes every session, token and remember cookie, so nothing can write new
 * personal data into a half-erased account. Notifying happens while the email
 * address still works. Erasing comes last.
 *
 * ## One transaction
 *
 * Every source runs inside a single transaction. A partial erasure is the
 * worst outcome available: the person has been told they are gone, and they
 * are, from some tables. Either all of it or none of it.
 */
class AccountEraser
{
    public function __construct(
        private readonly PersonalDataRegistry $registry,
        private readonly AccountModerationService $moderation,
    ) {}

    /**
     * Erase the account behind one request and record what was removed.
     *
     * Returns the request with its report and completion stamp written.
     */
    public function complete(ErasureRequest $request): ErasureRequest
    {
        $user = $request->user;

        /*
         * Cut off access first. A session still writing to a cart while the
         * cart is being emptied is a race worth not having.
         */
        if ($user->status !== AccountStatus::Closed) {
            $this->moderation->close($user, 'Account erased at the account holder\'s request.');
        }

        /* While the address still reaches them. */
        $user->notify(new ErasureCompletedNotification($request));

        $report = $this->erase($user);

        $request->forceFill([
            'status' => ErasureStatus::Completed,
            'completed_at' => now(),
            'report' => $report,
        ])->save();

        /*
         * Audited against the request rather than the user: the subject of an
         * audit row is rendered by name in the console, and the user's name
         * is a tombstone by now.
         */
        audit(null, 'privacy.account.erased', $request, null, [
            'user_id' => $user->getKey(),
            'records_erased' => $request->recordsErased(),
            'tables' => array_keys($report),
        ], 'Data-subject erasure request.');

        AccountErased::dispatch($request->id, (int) $user->getKey());

        return $request;
    }

    /**
     * Run every registered source against one account.
     *
     * @return array<string, array<string, int>>
     */
    public function erase(User $user): array
    {
        $anonymiser = Anonymiser::for($user);

        return DB::transaction(function () use ($user, $anonymiser): array {
            $report = [];

            foreach ($this->registry->all() as $source) {
                $tally = $source->erase($user, $anonymiser);

                /*
                 * A source that touched nothing is left out, so the report
                 * reads as a list of what happened rather than a list of
                 * modules with zeroes beside them.
                 */
                $tally = array_filter($tally, static fn (int $count): bool => $count > 0);

                if ($tally !== []) {
                    $report[$source->key()] = $tally;
                }
            }

            return $report;
        });
    }
}
