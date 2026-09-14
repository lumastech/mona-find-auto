<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Admin\Services\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reading the append-only trail: who did what, to what, and why.
 *
 * Every staff role may read it. A moderator who can see that somebody else
 * unpublished a listing is precisely the check the trail exists to provide,
 * and a record its own subjects cannot inspect is not much of one.
 *
 * Exporting is narrower — see AuditLogPolicy. The export streams the same
 * filtered query the screen is showing, so what leaves the platform is what
 * the operator was looking at rather than the whole table.
 */
class AuditLogController extends Controller
{
    public function __construct(private readonly AuditTrail $trail) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AuditLog::class);

        $filters = $this->filters($request);

        return Inertia::render('admin/audit/Index', [
            'entries' => $this->trail->paginate($filters)->through(
                fn (AuditLog $entry): array => $this->summarise($entry),
            ),
            'filters' => $filters,
            'actions' => $this->trail->actions(),
            'subjectTypes' => $this->trail->subjectTypes(),
            'canExport' => $request->user()?->can('export', AuditLog::class) ?? false,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('export', AuditLog::class);

        $filters = $this->filters($request);

        /*
         * The export is itself a staff action worth recording: this is the
         * one route by which the trail leaves the platform.
         */
        audit($request->user(), 'audit.exported', null, null, $filters, 'CSV export from the audit viewer.');

        return $this->trail->export($filters);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'action' => ['nullable', 'string', 'max:120'],
            'actor_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'subject_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(AuditLog $entry): array
    {
        return [
            'id' => $entry->id,
            'action' => $entry->action,
            'actor' => $entry->actor_label,
            'actor_id' => $entry->actor_id,
            'subject_type' => $entry->subject_type,
            'subject_label' => $entry->subject_type === null
                ? null
                : class_basename($entry->subject_type).' #'.$entry->subject_id,
            'subject_id' => $entry->subject_id,
            'reason' => $entry->reason,
            'before' => $entry->before,
            'after' => $entry->after,
            'channel' => $entry->context['channel'] ?? null,
            'ip' => $entry->context['ip'] ?? null,
            'created_at' => $entry->created_at->toIso8601String(),
        ];
    }
}
