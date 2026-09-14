<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Models;

use App\Models\User;
use App\Modules\Ratings\Database\Factories\RatingFactory;
use App\Modules\Ratings\Enums\RatingDirection;
use App\Modules\Ratings\Enums\RatingSource;
use App\Modules\Ratings\Enums\RatingStatus;
use App\Modules\Ratings\Enums\ReportStatus;
use App\Support\Content\ScreenFlag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One party's rating of another.
 *
 * The important thing about this row is what it is attached to. `source` is
 * the completed order or the endorsement that entitled somebody to write it,
 * and it is unique per direction, so the brief's rule — one rating per
 * counter-party per completed order — is a database constraint rather than a
 * convention.
 *
 * Visibility is decided by two independent things and both have to say yes:
 * the direction (a seller's rating of a buyer is never public, whatever its
 * status) and the moderation status (a published review is on the page, one
 * in the queue or hidden is not). Every query that reaches a buyer goes
 * through scopePublic(); every query that reaches a seller or staff goes
 * through scopeVisibleTo(). Nothing should be assembling `where` clauses
 * about visibility anywhere else.
 *
 * `body` is the text as published. The original is not kept — see the
 * migration for why.
 *
 * @property int $id
 * @property RatingDirection $direction
 * @property RatingSource $source
 * @property string $source_type
 * @property int $source_id
 * @property string $rater_type
 * @property int $rater_id
 * @property string $ratee_type
 * @property int $ratee_id
 * @property int $submitted_by
 * @property int $stars
 * @property string|null $body
 * @property RatingStatus $status
 * @property bool $verified_purchase
 * @property array<int, string>|null $screen_flags
 * @property string|null $reply_body
 * @property int|null $replied_by
 * @property Carbon|null $replied_at
 * @property int $reports_count
 * @property string|null $moderation_reason
 * @property int|null $moderated_by
 * @property Carbon|null $moderated_at
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model $rater
 * @property-read Model $ratee
 * @property-read Model $sourceRecord
 * @property-read User $submitter
 * @property-read User|null $replier
 * @property-read User|null $moderator
 * @property-read Collection<int, RatingReport> $reports
 */
class Rating extends Model implements HasMedia
{
    /** @use HasFactory<RatingFactory> */
    use HasFactory, InteractsWithMedia;

    /** Photographs the reviewer attached: the part as it arrived. */
    public const PHOTOS_COLLECTION = 'photos';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => RatingDirection::class,
            'source' => RatingSource::class,
            'status' => RatingStatus::class,
            'stars' => 'integer',
            'reports_count' => 'integer',
            'verified_purchase' => 'boolean',
            'screen_flags' => 'array',
            'replied_at' => 'datetime',
            'moderated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * The party who gave the rating: a User for a buyer, a Seller for a shop.
     *
     * @return MorphTo<Model, $this>
     */
    public function rater(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The party the rating is about.
     *
     * @return MorphTo<Model, $this>
     */
    public function ratee(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The completed order or endorsement this hangs off.
     *
     * Named `sourceRecord` rather than `source` because `source` is the enum
     * column saying which kind it is.
     *
     * @return MorphTo<Model, $this>
     */
    public function sourceRecord(): MorphTo
    {
        return $this->morphTo('source');
    }

    /**
     * The human who typed it — the same as the rater for a buyer, a member of
     * shop staff when a business is rating somebody.
     *
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function replier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    /**
     * @return HasMany<RatingReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(RatingReport::class)->latest('id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::PHOTOS_COLLECTION)
            ->useDisk('local')
            ->storeConversionsOnDisk('public')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif']);
    }

    /**
     * The same two sizes a listing photo gets, minus the watermark: a review
     * photo is the buyer's evidence, not the platform's marketing.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->performOnCollections(self::PHOTOS_COLLECTION)
            ->nonQueued()
            ->format('webp')
            ->fit(Fit::Contain, 320, 320);

        $this->addMediaConversion('web')
            ->performOnCollections(self::PHOTOS_COLLECTION)
            ->format('webp')
            ->fit(Fit::Contain, 1200, 1200);
    }

    /**
     * Whether a guest or buyer may read this rating.
     *
     * Both halves matter: a five-star review in the moderation queue is not
     * public yet, and a seller's rating of a buyer is never public at all.
     */
    public function isPubliclyVisible(): bool
    {
        return $this->direction->isPublic() && $this->status->isVisible();
    }

    public function hasReply(): bool
    {
        return filled($this->reply_body);
    }

    /**
     * The name a review is signed with.
     *
     * A shop signs with its business name — it is a trading identity and the
     * whole point is that buyers recognise it. A person signs with a first
     * name and an initial: enough that a review reads as having been written
     * by somebody, not enough to put a named buyer's opinion of a shop in
     * front of that shop's counter staff.
     */
    public function authorName(): string
    {
        $rater = $this->rater;

        if ($rater instanceof User) {
            return $this->shortenPersonalName($rater->name);
        }

        $businessName = $rater->getAttribute('business_name');

        return is_string($businessName) ? $businessName : 'MonaFind user';
    }

    /**
     * "Chanda Mwale" becomes "Chanda M."
     */
    private function shortenPersonalName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $parts = array_values(array_filter($parts));

        if ($parts === []) {
            return 'MonaFind buyer';
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        return $parts[0].' '.mb_strtoupper(mb_substr((string) end($parts), 0, 1)).'.';
    }

    /**
     * Whether the automatic screen removed anything from the text.
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
     * Whether this person is the party the rating is about.
     *
     * The check a reply is gated on. Compared on the morph class rather than
     * on `instanceof` so a Seller and a User with the same id cannot be
     * mistaken for each other.
     */
    public function isRatee(Model $party): bool
    {
        return $this->ratee_type === $party->getMorphClass()
            && $this->ratee_id === $party->getKey();
    }

    public function isRater(Model $party): bool
    {
        return $this->rater_type === $party->getMorphClass()
            && $this->rater_id === $party->getKey();
    }

    /**
     * What everybody may read: public directions, published only.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePublic(Builder $query): void
    {
        $query->whereIn('direction', RatingDirection::publicValues())
            ->where('status', RatingStatus::Published);
    }

    /**
     * Ratings about one party.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAbout(Builder $query, Model $party): void
    {
        $query->where('ratee_type', $party->getMorphClass())
            ->where('ratee_id', $party->getKey());
    }

    /**
     * Ratings given by one party.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeBy(Builder $query, Model $party): void
    {
        $query->where('rater_type', $party->getMorphClass())
            ->where('rater_id', $party->getKey());
    }

    /**
     * The ones whose stars count towards an aggregate — everything staff have
     * not taken down.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeCounted(Builder $query): void
    {
        $query->whereNot('status', RatingStatus::Hidden);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeWithStatus(Builder $query, ?RatingStatus $status): void
    {
        if ($status !== null) {
            $query->where('status', $status);
        }
    }

    /**
     * The moderation queue: flagged by the screen or objected to by a person.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeNeedingReview(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->where('status', RatingStatus::PendingReview)
                ->orWhereHas(
                    'reports',
                    static fn (Builder $reports): Builder => $reports->whereIn('status', ReportStatus::openValues()),
                );
        });
    }
}
