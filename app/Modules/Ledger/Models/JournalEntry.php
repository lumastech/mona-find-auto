<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Models\User;
use App\Modules\Ledger\Enums\PostingRecipe;
use App\Modules\Ledger\Services\LedgerService;
use App\Modules\Ledger\Support\PostingGuard;
use App\Support\Database\AppendOnly;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One balanced movement of money.
 *
 * Three guarantees, none of which live in this class — and that is the point.
 * Balance is checked by Posting, immutability by the AppendOnly trait and the
 * database triggers, uniqueness by the index on `idempotency_key`. The model
 * only refuses to be created outside a posting, which is what stops anything
 * writing an entry that skipped all three.
 *
 * There is deliberately no factory. A journal entry that a factory could
 * fabricate is an entry that did not balance, carried no idempotency key and
 * never reached the materialised balances — and being append-only, it could
 * never be corrected afterwards. Tests build entries the way production does,
 * through LedgerService::post().
 *
 * @property int $id
 * @property string $uuid
 * @property string $idempotency_key
 * @property PostingRecipe $recipe
 * @property string $description
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $created_by
 * @property string $actor_label
 * @property Money $total_ngwee
 * @property array<string, mixed>|null $context
 * @property Carbon $posted_at
 * @property Carbon|null $created_at
 * @property-read Collection<int, JournalLine> $lines
 * @property-read User|null $author
 * @property-read Model|null $reference
 *
 * @see LedgerService::post()
 */
class JournalEntry extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipe' => PostingRecipe::class,
            'total_ngwee' => MoneyCast::class,
            'context' => 'array',
            'posted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            PostingGuard::assertOpen($entry);

            if (blank($entry->uuid)) {
                $entry->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What the movement was about — an order, a payout, an adjustment.
     *
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * A short label for the thing this entry was about, for the browser
     * listing. Reads the reference's route key rather than its id, so an
     * order shows as "MF-7K2QX9BD" and not as "412".
     */
    public function referenceLabel(): ?string
    {
        if ($this->reference_type === null) {
            return null;
        }

        $model = $this->reference;

        if ($model === null) {
            return class_basename($this->reference_type).' #'.$this->reference_id;
        }

        return class_basename($model).' '.$model->getRouteKey();
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeWithRecipe(Builder $query, ?PostingRecipe $recipe): void
    {
        if ($recipe !== null) {
            $query->where('recipe', $recipe);
        }
    }

    /**
     * Entries touching one account, whatever side they touched it on.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeTouchingAccount(Builder $query, ?LedgerAccount $account): void
    {
        if ($account !== null) {
            $query->whereHas('lines', fn (Builder $lines) => $lines->where('ledger_account_id', $account->getKey()));
        }
    }

    /**
     * Entries about one particular thing.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAbout(Builder $query, ?Model $reference): void
    {
        if ($reference !== null) {
            $query->where('reference_type', $reference->getMorphClass())
                ->where('reference_id', $reference->getKey());
        }
    }
}
