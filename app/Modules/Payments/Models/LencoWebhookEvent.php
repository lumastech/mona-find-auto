<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Database\Factories\LencoWebhookEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A signature-verified webhook, stored before anything acts on it.
 *
 * The row is the idempotency record: `lenco_event_id` is unique, so a
 * redelivery loses the insert and is acknowledged without being queued again.
 *
 * @property int $id
 * @property string $lenco_event_id
 * @property string $event
 * @property string|null $reference
 * @property string|null $lenco_id
 * @property array<string, mixed> $payload
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property int $attempts
 * @property string|null $last_error
 */
class LencoWebhookEvent extends Model
{
    /** @use HasFactory<LencoWebhookEventFactory> */
    use HasFactory;

    protected $table = 'lenco_webhook_events';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * The event's own id, or a fingerprint of the body when Lenco sends none.
     *
     * Hashing the body is not as good as a real id — a genuinely identical
     * event sent twice on purpose would be collapsed into one — but for
     * payment events the body carries a reference and a status, so two
     * identical bodies really are the same fact stated twice.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function identify(array $payload, string $rawBody): string
    {
        $id = $payload['id'] ?? $payload['data']['id'] ?? null;
        $event = (string) ($payload['event'] ?? 'unknown');

        return is_string($id) && $id !== ''
            ? $event.':'.$id
            : $event.':sha256:'.hash('sha256', $rawBody);
    }

    /**
     * Pull our reference out of whichever shape the event uses.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function referenceFrom(array $payload): ?string
    {
        $reference = $payload['data']['reference'] ?? $payload['reference'] ?? null;

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /**
     * The event body, which is where every handler's data actually lives.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $data = $this->payload['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    /**
     * Events that arrived and were never dealt with — the thing to alert on.
     *
     * @param  Builder<LencoWebhookEvent>  $query
     * @return Builder<LencoWebhookEvent>
     */
    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->whereNull('processed_at');
    }
}
