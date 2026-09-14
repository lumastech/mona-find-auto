<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Orders\Database\Factories\OrderStatusEventFactory;
use App\Modules\Orders\Enums\OrderActorType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Support\Database\AppendOnly;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One move an order made.
 *
 * Append-only, both in this trait and in a database trigger. The timeline
 * shown to a buyer is decoration; what this table is actually for is the
 * moment a dispute turns on whether the part really was ready for collection
 * on the Tuesday, and the answer has to be a row nobody could have amended
 * afterwards.
 *
 * @property int $id
 * @property int $order_id
 * @property OrderStatus|null $from_status
 * @property OrderStatus $to_status
 * @property OrderActorType $actor_type
 * @property int|null $actor_id
 * @property string|null $reason
 * @property array<string, mixed>|null $context
 * @property Carbon $created_at
 * @property-read Order $order
 * @property-read User|null $actor
 */
class OrderStatusEvent extends Model
{
    /** @use HasFactory<OrderStatusEventFactory> */
    use AppendOnly, HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'actor_type' => OrderActorType::class,
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The line the timeline shows: what happened, and who did it.
     */
    public function headline(): string
    {
        return $this->to_status->label();
    }

    /**
     * Who to name against this entry.
     *
     * A system move is attributed to MonaFind rather than to a blank, because
     * "nobody" reads as missing data and "MonaFind" reads as what actually
     * happened: a timer ran out.
     */
    public function actorName(): string
    {
        if (! $this->actor_type->isPerson()) {
            return $this->actor_type->label();
        }

        return $this->actor->name ?? $this->actor_type->label();
    }
}
