<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Enums\BodyType;
use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\DriveType;
use App\Modules\Catalog\Enums\FuelType;
use App\Modules\Catalog\Enums\PartSourcing;
use App\Modules\Catalog\Enums\Transmission;
use App\Modules\Catalog\Models\Product;
use Illuminate\Validation\Rule;

/**
 * The listing rules, in one place.
 *
 * The seller form, the media endpoints and the API all validate against
 * these. The Vue form mirrors them client-side, so the rule that decides
 * whether a listing is acceptable is written once here and echoed there
 * rather than invented twice.
 *
 * The condition is deliberately not enforced against the seller's type here:
 * that belongs to ProductService, which forces it for a car breaker whatever
 * the payload said. Validation would only tell the seller off for something
 * the platform is going to decide for them anyway.
 */
trait ListingFieldRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function listingRules(): array
    {
        $currentYear = (int) date('Y');

        return [
            'name' => ['required', 'string', 'min:5', 'max:180'],
            'description' => ['required', 'string', 'min:20', 'max:8000'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],

            'make_id' => ['nullable', 'integer', Rule::exists('makes', 'id')],
            'vehicle_model_id' => ['nullable', 'integer', Rule::exists('vehicle_models', 'id')],
            'year_from' => ['nullable', 'integer', 'min:1950', 'max:'.($currentYear + 1)],
            /* A range that ends before it starts is a typo, not a fitment. */
            'year_to' => ['nullable', 'integer', 'gte:year_from', 'max:'.($currentYear + 1)],

            'condition' => ['required', Rule::enum(Condition::class)],
            'sourcing' => ['required', Rule::enum(PartSourcing::class)],

            'part_number' => ['nullable', 'string', 'max:80'],
            'oem_number' => ['nullable', 'string', 'max:80'],

            'engine_size_cc' => ['nullable', 'integer', 'min:50', 'max:30000'],
            'engine_code' => ['nullable', 'string', 'max:40'],
            'fuel_type' => ['nullable', Rule::enum(FuelType::class)],
            'transmission' => ['nullable', Rule::enum(Transmission::class)],
            'drive_type' => ['nullable', Rule::enum(DriveType::class)],
            'body_type' => ['nullable', Rule::enum(BodyType::class)],
            'trim' => ['nullable', 'string', 'max:60'],
            'chassis_compatibility' => ['nullable', 'string', 'max:2000'],

            'warranty_text' => ['nullable', 'string', 'max:1000'],
            'delivery_available' => ['boolean'],

            /*
             * A single-price listing sends price and quantity at the top
             * level; one with options sends the variants array instead. Each
             * is required only when the other is absent.
             */
            'price' => ['required_without:variants', 'nullable', 'decimal:0,2', 'gt:0'],
            'quantity' => ['required_without:variants', 'nullable', 'integer', 'min:0', 'max:100000'],

            'variants' => ['nullable', 'array', 'max:50'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['nullable', 'string', 'max:120'],
            'variants.*.sku' => ['nullable', 'string', 'max:64'],
            'variants.*.price' => ['required', 'decimal:0,2', 'gt:0'],
            'variants.*.quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'variants.*.is_default' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function listingMessages(): array
    {
        return [
            'name.min' => 'Say what the part is — a buyer searching for it needs more than a word.',
            'description.min' => 'Describe the part so a buyer knows what they are getting.',
            'category_id.required' => 'Choose the category this part belongs in.',
            'year_to.gte' => 'The last year a part fits cannot be before the first.',
            'price.gt' => 'A listing needs a price above zero.',
            'price.required_without' => 'Give this listing a price.',
            'variants.*.price.gt' => 'Every option needs a price above zero.',
        ];
    }

    /**
     * The photo upload rules, shared by the create form and the media manager.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function photoRules(bool $required = false): array
    {
        return [
            'photos' => [$required ? 'required' : 'nullable', 'array', 'max:'.Product::MAX_PHOTOS],
            'photos.*' => [
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.(int) config('catalog.media.max_photo_kilobytes'),
            ],
        ];
    }

    /**
     * The video upload rules.
     *
     * The length limit cannot be checked here — nothing in PHP reads a
     * duration without decoding the file — so the transcode job enforces it
     * and tells the seller why a clip was dropped.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function videoRules(): array
    {
        return [
            'video' => [
                'required',
                'file',
                'mimetypes:video/mp4,video/quicktime,video/webm,video/x-matroska',
                'max:'.(int) config('catalog.media.max_video_kilobytes'),
            ],
        ];
    }
}
