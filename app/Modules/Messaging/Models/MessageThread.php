<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Models\User;
use App\Modules\Messaging\Database\Factories\MessageThreadFactory;
use App\Modules\Messaging\Enums\ThreadRole;
use App\Modules\Messaging\Services\ThreadSubjectRegistry;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A conversation about a listing, a quote request or an order.
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $dedupe_key
 * @property int|null $seller_id
 * @property string $subject_label
 * @property int $messages_count
 * @property Carbon|null $last_message_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $subject
 * @property-read Seller|null $seller
 * @property-read Collection<int, Message> $messages
 * @property-read Collection<int, MessageThreadParticipant> $participants
 */
#[Fillable([
    'subject_type',
    'subject_id',
    'dedupe_key',
    'seller_id',
    'subject_label',
])]
class MessageThread extends Model
{
    /** @use HasFactory<MessageThreadFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'messages_count' => 'integer',
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->oldest('id');
    }

    /**
     * @return HasMany<MessageThreadParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(MessageThreadParticipant::class);
    }

    /**
     * The key that makes "one thread per subject per group of people" true.
     *
     * Hashed rather than concatenated so the column has a fixed width
     * whatever the morph class is called, and sorted so that whoever opens
     * the conversation lands on the same key.
     *
     * @param  array<int, int>  $userIds
     */
    public static function dedupeKeyFor(Model $subject, array $userIds): string
    {
        sort($userIds);

        return hash('sha256', implode('|', [
            $subject->getMorphClass(),
            (string) $subject->getKey(),
            implode(',', $userIds),
        ]));
    }

    public function participantFor(User $user): ?MessageThreadParticipant
    {
        return $this->participants->firstWhere('user_id', $user->getKey());
    }

    public function hasParticipant(User $user): bool
    {
        return $this->participants()->where('user_id', $user->getKey())->exists();
    }

    /**
     * The other side, from one participant's point of view.
     */
    public function counterpartTo(User $user): ?MessageThreadParticipant
    {
        return $this->participants->first(
            static fn (MessageThreadParticipant $participant): bool => $participant->user_id !== $user->getKey(),
        );
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * Whether messages here may carry phone numbers, addresses and links.
     *
     * Delegated to the subject's resolver rather than decided here, because
     * "has this been paid for" means something different for an order and for
     * a listing nobody has bought yet. A subject nothing has claimed screens
     * its messages — the safe answer when we do not know.
     */
    public function allowsContactDetails(): bool
    {
        $subject = $this->subject;

        if ($subject === null) {
            return false;
        }

        return app(ThreadSubjectRegistry::class)->find($subject)?->isPaid($subject) ?? false;
    }

    /**
     * Whether this person has messages here they have not seen.
     *
     * Their own message does not count: last_read_at is stamped when they
     * post, so a thread never comes back unread because you answered it.
     */
    public function isUnreadFor(User $user): bool
    {
        $participant = $this->participantFor($user);

        if ($participant === null || $this->last_message_at === null) {
            return false;
        }

        return $participant->last_read_at === null
            || $participant->last_read_at->lt($this->last_message_at);
    }

    /**
     * Threads this person is in.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForParticipant(Builder $query, User $user): void
    {
        $query->whereHas(
            'participants',
            static fn (Builder $participants) => $participants->where('user_id', $user->getKey()),
        );
    }

    /**
     * A shop's inbox — every conversation about it, whoever on the shop's
     * side is reading.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForSeller(Builder $query, Seller $seller): void
    {
        $query->where('seller_id', $seller->getKey());
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeWithRole(Builder $query, User $user, ThreadRole $role): void
    {
        $query->whereHas('participants', static fn (Builder $participants) => $participants
            ->where('user_id', $user->getKey())
            ->where('role', $role->value));
    }

    /**
     * Newest activity first, with threads nobody has written in yet last.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeRecentFirst(Builder $query): void
    {
        $query->orderByDesc('last_message_at')->orderByDesc('id');
    }
}
