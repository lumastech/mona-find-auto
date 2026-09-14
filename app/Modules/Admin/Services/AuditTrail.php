<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reading the append-only audit trail.
 *
 * The table is written from everywhere and read from one screen, and it grows
 * for ever — nothing prunes it, because a record that can be aged out is not
 * an audit trail. That shapes both halves of this class: the filters are all
 * indexed columns, and the export streams rather than collecting, because the
 * answer to "every money movement last quarter" is not something that should
 * be held in memory to be turned into a string.
 */
class AuditTrail
{
    /** Rows fetched per chunk while streaming an export. */
    private const EXPORT_CHUNK = 500;

    /**
     * @param  array{actor?: string|null, action?: string|null, subject_type?: string|null, subject_id?: int|string|null, from?: string|null, to?: string|null, search?: string|null}  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        return $this->query($filters)
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The distinct actions present, for the filter dropdown.
     *
     * Read off the table rather than from a list of constants: the actions
     * are written by a dozen modules and any hard-coded list here would be
     * out of date the first time one of them added a verb.
     *
     * @return array<int, string>
     */
    public function actions(): array
    {
        return AuditLog::query()
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();
    }

    /**
     * The distinct subject types present, as short class names.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function subjectTypes(): array
    {
        return AuditLog::query()
            ->whereNotNull('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type')
            ->map(static fn (string $type): array => [
                'value' => $type,
                'label' => class_basename($type),
            ])
            ->all();
    }

    /**
     * The same rows the screen is showing, as a CSV download.
     *
     * `before` and `after` go out as JSON in one column each rather than
     * being flattened into columns of their own: every action has a different
     * shape, and a spreadsheet with one column per field any action ever
     * wrote would be thousands of columns wide and almost entirely empty.
     *
     * @param  array<string, mixed>  $filters
     */
    public function export(array $filters): StreamedResponse
    {
        $query = $this->query($filters)->orderBy('id');
        $filename = 'monafind-audit-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'id', 'recorded_at', 'actor', 'actor_type', 'actor_id',
                'action', 'subject_type', 'subject_id', 'reason',
                'before', 'after', 'ip', 'route',
            ]);

            $query->chunkById(self::EXPORT_CHUNK, function ($rows) use ($handle): void {
                foreach ($rows as $row) {
                    fputcsv($handle, $this->toRow($row));
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function toRow(AuditLog $entry): array
    {
        $context = $entry->context ?? [];

        return [
            (string) $entry->id,
            $entry->created_at->toIso8601String(),
            (string) $entry->actor_label,
            (string) $entry->actor_type,
            (string) $entry->actor_id,
            $entry->action,
            (string) $entry->subject_type,
            (string) $entry->subject_id,
            (string) $entry->reason,
            $this->json($entry->before),
            $this->json($entry->after),
            (string) ($context['ip'] ?? ''),
            (string) ($context['route'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    private function json(?array $value): string
    {
        return $value === null ? '' : (string) json_encode($value);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AuditLog>
     */
    private function query(array $filters): Builder
    {
        return AuditLog::query()
            ->when(
                $this->filled($filters, 'action'),
                fn (Builder $query) => $query->where('action', $filters['action']),
            )
            ->when(
                $this->filled($filters, 'actor_id'),
                fn (Builder $query) => $query->where('actor_id', $filters['actor_id']),
            )
            ->when(
                $this->filled($filters, 'subject_type'),
                fn (Builder $query) => $query->where('subject_type', $filters['subject_type']),
            )
            ->when(
                $this->filled($filters, 'subject_id'),
                fn (Builder $query) => $query->where('subject_id', $filters['subject_id']),
            )
            /*
             * Dates arrive as the operator's own day in Lusaka. Taking them
             * as UTC would silently shift the boundary by two hours and drop
             * the evening's entries out of "today".
             */
            ->when(
                $this->filled($filters, 'from'),
                fn (Builder $query) => $query->where('created_at', '>=', $this->startOfDay((string) $filters['from'])),
            )
            ->when(
                $this->filled($filters, 'to'),
                fn (Builder $query) => $query->where('created_at', '<=', $this->endOfDay((string) $filters['to'])),
            )
            ->when(
                $this->filled($filters, 'search'),
                fn (Builder $query) => $query->where(function (Builder $query) use ($filters): void {
                    $term = '%'.$filters['search'].'%';

                    $query->where('actor_label', 'like', $term)->orWhere('reason', 'like', $term);
                }),
            );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filled(array $filters, string $key): bool
    {
        return isset($filters[$key]) && $filters[$key] !== '';
    }

    private function startOfDay(string $date): Carbon
    {
        return Carbon::parse($date, config('monafind.display_timezone'))->startOfDay()->utc();
    }

    private function endOfDay(string $date): Carbon
    {
        return Carbon::parse($date, config('monafind.display_timezone'))->endOfDay()->utc();
    }
}
