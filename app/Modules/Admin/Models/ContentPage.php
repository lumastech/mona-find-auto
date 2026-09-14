<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Modules\Admin\Database\Factories\ContentPageFactory;
use App\Modules\Admin\Enums\ContentPageStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One page MonaFind writes about itself: About, FAQ, Contact, Terms, Privacy.
 *
 * The row is the page's identity — its slug, where it sits in the footer,
 * whether it is live. The WORDS are not here; they are in the versions, and
 * this row points at whichever one is current. Editing a page writes a new
 * version rather than overwriting the old, so the terms a buyer accepted in
 * March can still be produced in September.
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property ContentPageStatus $status
 * @property int|null $current_version_id
 * @property bool $is_system
 * @property bool $show_in_footer
 * @property int $position
 * @property string|null $meta_description
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ContentPageVersion|null $currentVersion
 * @property-read Collection<int, ContentPageVersion> $versions
 */
class ContentPage extends Model
{
    /** @use HasFactory<ContentPageFactory> */
    use HasFactory;

    /** The platform terms, whose version feeds every checkout acceptance. */
    public const TERMS_SLUG = 'platform-terms';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ContentPageStatus::class,
            'is_system' => 'boolean',
            'show_in_footer' => 'boolean',
            'position' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ContentPageVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ContentPageVersion::class, 'current_version_id');
    }

    /**
     * @return HasMany<ContentPageVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ContentPageVersion::class)->orderByDesc('version');
    }

    /**
     * The version this page is currently serving, if it has ever been
     * published.
     *
     * Spelled out as a nullable accessor rather than read straight off the
     * relation: `current_version_id` is nullable by design — a page can be
     * created and never written — and the relation's own type does not say so.
     */
    public function liveVersion(): ?ContentPageVersion
    {
        return $this->current_version_id === null ? null : $this->currentVersion;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function isPublished(): bool
    {
        return $this->status === ContentPageStatus::Published && $this->current_version_id !== null;
    }

    /**
     * Whether this page is the one checkout quotes.
     */
    public function isPlatformTerms(): bool
    {
        return $this->slug === self::TERMS_SLUG;
    }

    /**
     * The pages a visitor may open.
     *
     * A published page with no version has never been written; it would
     * render blank, so it is not live whatever the status column says.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', ContentPageStatus::Published)->whereNotNull('current_version_id');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeInFooter(Builder $query): void
    {
        $query->live()->where('show_in_footer', true)->orderBy('position')->orderBy('title');
    }
}
