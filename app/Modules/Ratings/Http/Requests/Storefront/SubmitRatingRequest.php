<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Http\Requests\Storefront;

use App\Modules\Ratings\Enums\RatingDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Stars, optionally some words, optionally some photographs.
 *
 * The body is optional on purpose. Most people who will rate anything at all
 * will tap five stars and leave; insisting on a sentence turns a review that
 * would have been left into one that was not, and the star is the part the
 * aggregate is built from.
 *
 * Whether this person may rate at all is NOT decided here. That is
 * RatingEligibility's job, because the same question has to be answered
 * identically for the web form, the API and the card that offered the form in
 * the first place.
 */
class SubmitRatingRequest extends FormRequest
{
    public const MAX_PHOTOS = 4;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::enum(RatingDirection::class)],
            'stars' => ['required', 'integer', 'between:1,5'],
            'body' => ['nullable', 'string', 'max:2000'],
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
            'stars.between' => __('Choose between 1 and 5 stars.'),
            'photos.max' => __('You can attach up to :count photos.', ['count' => self::MAX_PHOTOS]),
        ];
    }

    public function direction(): RatingDirection
    {
        return RatingDirection::from((string) $this->validated('direction'));
    }

    public function stars(): int
    {
        return (int) $this->validated('stars');
    }

    public function body(): ?string
    {
        $body = $this->validated('body');

        return is_string($body) ? $body : null;
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
