<?php

declare(strict_types=1);

namespace App\Support\Alerts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Sends operational alerts, and — just as importantly — stops sending them.
 *
 * ## The suppression is the point
 *
 * The failure modes worth alerting on are the ones that repeat. A gateway
 * that is refusing every collection produces one alert per buyer; a worker
 * crash-looping produces one per job. Five hundred identical emails do not
 * convey five hundred times the information — they convey none, because the
 * operator has moved the lot to a folder.
 *
 * So each alert carries a `$key` naming what it is about, and an identical
 * key is suppressed for the level's cooldown. The count of what was
 * suppressed rides along on the next one that gets through, so nothing is
 * silently lost.
 *
 * ## Every alert is logged even when it is not sent
 *
 * The log is the complete record; the mail is the interruption. An operator
 * investigating afterwards needs every occurrence, not the ones that happened
 * to fall outside a cooldown window.
 *
 * ## Never throws
 *
 * An alert is something that happens when something has already gone wrong.
 * A misconfigured mailer must not turn a reconciliation variance into a
 * failed job that itself triggers an alert that also fails.
 */
class AlertDispatcher
{
    private const CACHE_PREFIX = 'alerts:';

    /**
     * Raise an alert about `$key`.
     *
     * @param  array<string, scalar|null>  $context
     */
    public function raise(
        string $key,
        AlertLevel $level,
        string $summary,
        array $context = [],
        ?string $actionUrl = null,
        ?string $actionLabel = null,
    ): bool {
        $this->log($level, $summary, ['alert_key' => $key, ...$context]);

        $recipients = $this->recipients();

        if ($recipients === []) {
            return false;
        }

        $suppressed = $this->countSuppressed($key, $level);

        if ($suppressed !== null) {
            return false;
        }

        $repeats = $this->drainRepeats($key);

        if ($repeats > 0) {
            $context['suppressed_since_last_alert'] = $repeats;
        }

        try {
            Notification::route('mail', $recipients)->notify(
                new OperationalAlert($level, $summary, $context, $actionUrl, $actionLabel),
            );
        } catch (\Throwable $exception) {
            /*
             * An alert fires because something is already wrong. A broken
             * mailer must not escalate that into a failed job which raises
             * another alert which also fails.
             */
            Log::error('Could not deliver an operational alert.', [
                'alert_key' => $key,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * The addresses configured to receive alerts.
     *
     * @return array<int, string>
     */
    public function recipients(): array
    {
        $configured = config('security.alerts.recipients', []);

        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $address): string => trim((string) $address), $configured),
            static fn (string $address): bool => $address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
        ));
    }

    /**
     * Is this key inside its cooldown? Returns null when it is clear to send,
     * and increments the suppressed counter when it is not.
     */
    private function countSuppressed(string $key, AlertLevel $level): ?int
    {
        $cacheKey = self::CACHE_PREFIX.'sent:'.md5($key);

        if (Cache::get($cacheKey) === null) {
            Cache::put($cacheKey, true, now()->addMinutes($level->cooldownMinutes()));

            return null;
        }

        return $this->recordRepeat($key);
    }

    private function recordRepeat(string $key): int
    {
        $cacheKey = self::CACHE_PREFIX.'repeats:'.md5($key);

        /*
         * A day, so that the count survives the longest cooldown and a little
         * more. An orphaned counter costs one cache key.
         */
        Cache::add($cacheKey, 0, now()->addDay());

        return (int) Cache::increment($cacheKey);
    }

    private function drainRepeats(string $key): int
    {
        $cacheKey = self::CACHE_PREFIX.'repeats:'.md5($key);

        $count = (int) Cache::get($cacheKey, 0);

        Cache::forget($cacheKey);

        return $count;
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function log(AlertLevel $level, string $summary, array $context): void
    {
        match ($level) {
            AlertLevel::Critical => Log::critical($summary, $context),
            AlertLevel::Warning => Log::warning($summary, $context),
            AlertLevel::Notice => Log::notice($summary, $context),
        };
    }
}
