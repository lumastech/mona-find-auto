<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Models\User;
use App\Modules\Admin\Database\Factories\ContentPageVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a page said, at one version number.
 *
 * Versions are written and never edited. They are not marked append-only at
 * the database level the way the ledger is — nobody is being paid on the
 * strength of an About page — but the console offers no route that updates
 * one, and ContentPageService only ever inserts.
 *
 * @property int $id
 * @property int $content_page_id
 * @property int $version
 * @property string $title
 * @property string $body
 * @property string|null $change_note
 * @property int|null $created_by
 * @property string|null $created_by_label
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ContentPage $page
 * @property-read User|null $author
 */
class ContentPageVersion extends Model
{
    /** @use HasFactory<ContentPageVersionFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ContentPage, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(ContentPage::class, 'content_page_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Who wrote this version.
     *
     * Falls back to the label copied onto the row, so a staff account that
     * has since been deleted does not erase the authorship of what it wrote.
     */
    public function authorName(): ?string
    {
        $author = $this->author;

        return $author instanceof User ? $author->name : $this->created_by_label;
    }
}
