<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Models\User;
use App\Modules\Messaging\Database\Factories\MessageFactory;
use App\Support\Content\ScreenFlag;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One message in a thread, as it will be read.
 *
 * @property int $id
 * @property int $message_thread_id
 * @property int|null $user_id
 * @property string $body
 * @property array<int, string>|null $screen_flags
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MessageThread $thread
 * @property-read User|null $author
 */
#[Fillable(['message_thread_id', 'user_id', 'body', 'screen_flags'])]
class Message extends Model implements HasMedia
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, InteractsWithMedia;

    public const ATTACHMENTS_COLLECTION = 'attachments';

    /**
     * What may be attached.
     *
     * A constant rather than a list inside registerMediaCollections(), so the
     * validation rule and the storage read the same thing — a rule that
     * accepts what the collection then refuses is an upload that fails after
     * the person has waited for it.
     *
     * @var array<int, string>
     */
    public const ACCEPTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/avif',
        'application/pdf',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'screen_flags' => 'array',
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
     * Null for the lines the platform writes itself.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isFrom(User $user): bool
    {
        return $this->user_id === $user->getKey();
    }

    public function isFromPlatform(): bool
    {
        return $this->user_id === null;
    }

    /**
     * Whether the screen took something out of this message.
     */
    public function wasRedacted(): bool
    {
        foreach ($this->screenFlags() as $flag) {
            if (! $flag->requiresReview()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, ScreenFlag>
     */
    public function screenFlags(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $flag): ?ScreenFlag => ScreenFlag::tryFrom($flag),
            $this->screen_flags ?? [],
        )));
    }

    /**
     * Photographs of the part, the damage or the receipt.
     *
     * The same mime types a review photo accepts, plus PDF: a buyer arguing
     * about a warranty sends the slip they were given, and refusing it here
     * would push that conversation onto WhatsApp — which is exactly where a
     * disputed order should not be.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::ATTACHMENTS_COLLECTION)
            ->useDisk('local')
            ->storeConversionsOnDisk('public')
            ->acceptsMimeTypes(self::ACCEPTED_MIME_TYPES);
    }

    /**
     * One size, not two. A message attachment is looked at once, in a
     * conversation, on a phone — there is no gallery and no full-screen view
     * to feed, so the web size a listing photo gets would be a conversion
     * nobody opens.
     *
     * Images only. A PDF has no thumbnail without imagick and a PDF reader on
     * the box, and a conversion that throws on the media queue for every
     * receipt anybody attaches is worse than no thumbnail.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        if ($media !== null && ! str_starts_with((string) $media->mime_type, 'image/')) {
            return;
        }

        $this->addMediaConversion('thumb')
            ->performOnCollections(self::ATTACHMENTS_COLLECTION)
            ->nonQueued()
            ->format('webp')
            ->fit(Fit::Contain, 480, 480);
    }
}
