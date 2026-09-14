<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests\Storefront;

use App\Modules\Orders\Enums\DisputeReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * A buyer raising a problem: a reason, an account, and photographs.
 *
 * The details field has a floor as well as a ceiling. "Broken" tells a
 * moderator nothing and produces a dispute that has to be chased by phone
 * before it can be decided, so the form insists on a sentence.
 *
 * Photographs are limited in count and size because they arrive from phones
 * on Zambian mobile data, and an upload that times out at the fourth image is
 * a dispute that never gets raised.
 */
class OpenDisputeRequest extends FormRequest
{
    public const MAX_PHOTOS = 5;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(DisputeReason::class)],
            'details' => ['required', 'string', 'min:20', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:'.self::MAX_PHOTOS],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'details.min' => __('Tell us what went wrong in a sentence or two, so we can act on it.'),
            'photos.max' => __('You can attach up to :count photos.', ['count' => self::MAX_PHOTOS]),
        ];
    }

    public function reason(): DisputeReason
    {
        return DisputeReason::from((string) $this->validated('reason'));
    }

    /**
     * @return array<int, UploadedFile>
     */
    public function photos(): array
    {
        /** @var array<int, UploadedFile> $photos */
        $photos = $this->file('photos') ?? [];

        return $photos;
    }
}
