<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Models\User;
use App\Modules\Admin\Database\Factories\AnnouncementFactory;
use App\Modules\Admin\Enums\AnnouncementAudience;
use App\Modules\Admin\Enums\AnnouncementLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A banner across the top of one area, for a stated window.
 *
 * @property int $id
 * @property string $title
 * @property string $body
 * @property AnnouncementLevel $level
 * @property AnnouncementAudience $audience
 * @property string|null $link_url
 * @property string|null $link_label
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $author
 */
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => AnnouncementLevel::class,
            'audience' => AnnouncementAudience::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Banners live right now.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeShowing(Builder $query, ?Carbon $asOf = null): void
    {
        $now = $asOf ?? now();

        $query->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            });
    }

    /**
     * Whether this banner has run its course.
     *
     * Shown in the console so a scheduler can tell a banner that has not
     * started from one that is over — both are "not showing", and only one
     * of them is somebody forgetting to press publish.
     */
    public function hasEnded(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }

    public function hasStarted(): bool
    {
        return $this->starts_at->isPast();
    }

    public function isShowing(): bool
    {
        return $this->is_active && $this->hasStarted() && ! $this->hasEnded();
    }

    /**
     * The shape the Inertia banner component reads.
     *
     * @return array{id: int, title: string, body: string, level: string, dismissible: bool, link_url: string|null, link_label: string|null}
     */
    public function toBanner(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'level' => $this->level->value,
            'dismissible' => $this->level->isDismissible(),
            'link_url' => $this->link_url,
            'link_label' => $this->link_label,
        ];
    }
}
