<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes rows into the append-only audit trail.
 *
 * Every staff action and every money movement goes through here. Prefer the
 * audit() helper at call sites; this class exists so it can be swapped or
 * spied on in tests.
 */
class AuditRecorder
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $context
     */
    public function record(
        ?Model $actor,
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        array $context = [],
    ): AuditLog {
        $actor ??= $this->currentActor();

        return AuditLog::query()->create([
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'actor_label' => $this->labelFor($actor),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'context' => [...$this->requestContext(), ...$context],
            'created_at' => now(),
        ]);
    }

    private function currentActor(): ?Model
    {
        $user = auth()->user();

        return $user instanceof Model ? $user : null;
    }

    private function labelFor(?Model $actor): string
    {
        if ($actor === null) {
            return 'System';
        }

        foreach (['name', 'email', 'title'] as $attribute) {
            $value = $actor->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $actor->getMorphClass().'#'.$actor->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    private function requestContext(): array
    {
        if (app()->runningInConsole() || ! app()->bound('request')) {
            return ['channel' => 'console'];
        }

        $request = app(Request::class);

        return [
            'channel' => 'http',
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'method' => $request->method(),
            'route' => $request->route()?->getName(),
            'url' => $request->fullUrl(),
        ];
    }
}
