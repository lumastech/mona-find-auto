<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Models;

use App\Models\User;
use App\Modules\Sellers\Database\Factories\SellerRegistrationDraftFactory;
use App\Modules\Sellers\Enums\RegistrationStep;
use App\Modules\Sellers\Enums\SellerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A seller sign-up in progress.
 *
 * Sign-up asks for documents and bank details most people do not have on the
 * desk in front of them, so each step is saved as it is finished and the
 * draft becomes a Seller only at submit.
 *
 * @property int $id
 * @property int $user_id
 * @property RegistrationStep $current_step
 * @property RegistrationStep $furthest_step
 * @property array<string, array<string, mixed>> $data
 * @property Carbon|null $submitted_at
 * @property int|null $seller_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Seller|null $seller
 */
class SellerRegistrationDraft extends Model
{
    /** @use HasFactory<SellerRegistrationDraftFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $attributes = [
        'current_step' => 'type',
        'furthest_step' => 'type',
        'data' => '{}',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_step' => RegistrationStep::class,
            'furthest_step' => RegistrationStep::class,
            'data' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * Everything saved for one step.
     *
     * @return array<string, mixed>
     */
    public function step(RegistrationStep $step): array
    {
        return $this->data[$step->value] ?? [];
    }

    /**
     * Overwrite one step's answers, leaving the other steps alone.
     *
     * @param  array<string, mixed>  $values
     */
    public function putStep(RegistrationStep $step, array $values): self
    {
        $this->data = [...$this->data, $step->value => $values];

        if ($this->furthest_step->isAtOrBefore($step)) {
            $this->furthest_step = $step;
        }

        return $this;
    }

    /**
     * Everything typed so far, flattened — the shape the review step and the
     * submit action both read.
     *
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        $answers = [];

        foreach (RegistrationStep::cases() as $step) {
            $answers = [...$answers, ...$this->step($step)];
        }

        return $answers;
    }

    /**
     * The business type chosen on step one; null until it has been.
     */
    public function sellerType(): ?SellerType
    {
        $type = $this->step(RegistrationStep::Type)['type'] ?? null;

        return is_string($type) ? SellerType::tryFrom($type) : null;
    }

    /**
     * Whether the applicant may open a step: any step they have reached
     * before, plus the one after it. Nobody jumps to the end.
     */
    public function canOpen(RegistrationStep $step): bool
    {
        $furthest = $this->furthest_step->next() ?? $this->furthest_step;

        return $step->isAtOrBefore($furthest);
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }
}
