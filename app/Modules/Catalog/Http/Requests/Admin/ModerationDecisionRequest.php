<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests\Admin;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A moderator's decision on one listing.
 *
 * The per-field reasons are what makes a rejection actionable. A seller told
 * only "rejected" resubmits the same listing; a seller told "photos: too dark
 * to see the part" fixes the photos, which is the whole point of reviewing
 * one at a time.
 */
class ModerationDecisionRequest extends FormRequest
{
    /** The fields a moderator may pin a reason to, matching the seller's form. */
    public const REJECTABLE_FIELDS = [
        'name',
        'description',
        'category_id',
        'condition',
        'photos',
        'video',
        'price',
        'part_number',
        'fitment',
    ];

    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product && $this->user()?->can('moderate', $product) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'field_reasons' => ['nullable', 'array'],
            'field_reasons.*' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.min' => 'Tell the seller what to fix, not only that something is wrong.',
        ];
    }

    /**
     * The per-field reasons, dropped down to the fields that exist and the
     * ones the moderator actually filled in.
     *
     * @return array<string, string>
     */
    public function fieldReasons(): array
    {
        /** @var array<string, string|null> $reasons */
        $reasons = $this->input('field_reasons', []);

        return array_filter(
            array_intersect_key($reasons, array_flip(self::REJECTABLE_FIELDS)),
            static fn (?string $reason): bool => filled($reason),
        );
    }
}
