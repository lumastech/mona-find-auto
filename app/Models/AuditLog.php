<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database\AppendOnly;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * An immutable record of one staff action or one money movement.
 *
 * Write these through the audit() helper rather than creating them directly —
 * it fills in the actor and request context for you.
 *
 * @property int $id
 * @property string|null $actor_type
 * @property int|string|null $actor_id
 * @property string|null $actor_label
 * @property string $action
 * @property string|null $subject_type
 * @property int|string|null $subject_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $reason
 * @property array<string, mixed>|null $context
 * @property Carbon $created_at
 *
 * @use HasFactory<AuditLogFactory>
 */
class AuditLog extends Model
{
    use AppendOnly;

    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
