<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Models\User;
use App\Modules\Messaging\Database\Factories\MessageThreadParticipantFactory;
use App\Modules\Messaging\Enums\ThreadRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's membership of one thread, and how much of it they have read.
 *
 * @property int $id
 * @property int $message_thread_id
 * @property int $user_id
 * @property ThreadRole $role
 * @property Carbon|null $last_read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MessageThread $thread
 * @property-read User $user
 */
#[Fillable(['message_thread_id', 'user_id', 'role', 'last_read_at'])]
class MessageThreadParticipant extends Model
{
    /** @use HasFactory<MessageThreadParticipantFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ThreadRole::class,
            'last_read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MessageThread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'message_thread_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
