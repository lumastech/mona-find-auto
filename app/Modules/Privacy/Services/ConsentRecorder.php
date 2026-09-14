<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Services;

use App\Models\User;
use App\Modules\Privacy\Contracts\PolicyDocuments;
use App\Modules\Privacy\Enums\ConsentType;
use App\Modules\Privacy\Models\ConsentRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Writes and reads the consent log.
 *
 * ## Recording is unconditional
 *
 * `record()` writes a row whether the answer was yes or no. A refusal that
 * leaves no trace is indistinguishable from a question never asked, and the
 * difference matters: the first is a choice the platform has to respect, the
 * second is a gap it has to fill.
 *
 * ## Reading is "the newest row wins"
 *
 * There is no current-state column to fall out of step with the log. Asking
 * whether someone has agreed to marketing means finding their newest
 * marketing row, which is one indexed query.
 *
 * ## Re-consent after a policy change
 *
 * `needsReconsent()` compares the version stamped on the newest record with
 * the version now in force. A person who agreed to version 2 of the privacy
 * notice has not agreed to version 3, and the platform should ask again
 * rather than assume.
 */
class ConsentRecorder
{
    public function __construct(private readonly PolicyDocuments $documents) {}

    /**
     * Write one consent decision.
     *
     * `$source` says where it was collected — "registration", "settings",
     * "checkout", "api" — because a consent whose origin is unknown is hard
     * to defend as freely given.
     */
    public function record(
        User $user,
        ConsentType $type,
        bool $granted,
        string $source,
        ?Request $request = null,
    ): ConsentRecord {
        $slug = $type->contentPageSlug();

        $record = ConsentRecord::create([
            'user_id' => $user->getKey(),
            'type' => $type,
            'granted' => $granted,
            'document_slug' => $slug,
            'document_version' => $slug === null ? null : $this->documents->currentVersion($slug),
            'source' => $source,
            'ip_address' => $request?->ip(),
            /* Truncated: the column is text, but a forged 8KB header is not evidence. */
            'user_agent' => $this->userAgent($request),
            'recorded_at' => now(),
        ]);

        audit($user, $granted ? 'privacy.consent.granted' : 'privacy.consent.withdrawn', $record, null, [
            'type' => $type->value,
            'source' => $source,
            'document_version' => $record->document_version,
        ]);

        return $record;
    }

    /**
     * Record the consents a registration collected, in one pass.
     *
     * Required consents are written as granted because the form cannot be
     * submitted without them — the validation rule saw to that. Marketing is
     * written either way.
     */
    public function recordRegistration(User $user, bool $marketing, ?Request $request = null): void
    {
        foreach (ConsentType::requiredAtRegistration() as $type) {
            $this->record($user, $type, true, 'registration', $request);
        }

        $this->record($user, ConsentType::Marketing, $marketing, 'registration', $request);
    }

    /**
     * Where this person currently stands on one consent.
     */
    public function latestFor(User $user, ConsentType $type): ?ConsentRecord
    {
        return ConsentRecord::query()
            ->where('user_id', $user->getKey())
            ->where('type', $type)
            ->latestFirst()
            ->first();
    }

    public function hasGranted(User $user, ConsentType $type): bool
    {
        $latest = $this->latestFor($user, $type);

        return $latest !== null && $latest->granted;
    }

    /**
     * Has the document moved on since this person last agreed to it?
     *
     * False when they have never agreed at all — that is a missing consent,
     * not a stale one, and the two are handled in different places.
     */
    public function needsReconsent(User $user, ConsentType $type): bool
    {
        $slug = $type->contentPageSlug();

        if ($slug === null) {
            return false;
        }

        $latest = $this->latestFor($user, $type);

        if ($latest === null || ! $latest->granted) {
            return false;
        }

        $current = $this->documents->currentVersion($slug);

        return $current !== null
            && $latest->document_version !== null
            && $current > $latest->document_version;
    }

    /**
     * The whole log for one person, newest first — what the settings screen
     * shows and what the data export includes.
     *
     * @return Collection<int, ConsentRecord>
     */
    public function historyFor(User $user): Collection
    {
        return ConsentRecord::query()
            ->where('user_id', $user->getKey())
            ->latestFirst()
            ->get();
    }

    /**
     * The current position on every consent type, for the settings screen.
     *
     * @return array<int, array{type: string, label: string, granted: bool, withdrawable: bool, recorded_at: string|null, document_version: int|null}>
     */
    public function summaryFor(User $user): array
    {
        return array_map(function (ConsentType $type) use ($user): array {
            $latest = $this->latestFor($user, $type);

            return [
                'type' => $type->value,
                'label' => $type->label(),
                'granted' => $latest !== null && $latest->granted,
                'withdrawable' => $type->isWithdrawable(),
                'recorded_at' => $latest?->recorded_at?->toIso8601String(),
                'document_version' => $latest?->document_version,
            ];
        }, ConsentType::cases());
    }

    private function userAgent(?Request $request): ?string
    {
        $agent = $request?->userAgent();

        return is_string($agent) ? mb_substr($agent, 0, 512) : null;
    }
}
