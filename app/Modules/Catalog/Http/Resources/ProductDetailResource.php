<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\BackInStockSubscription;
use App\Modules\Sellers\Http\Resources\SellerPolicyResource;
use App\Modules\Sellers\Support\SellerContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The full listing page.
 *
 * The storefront page and /api/v1/products/{product} both answer through
 * this, so the contact-blur rule and the badge pair are applied once. The
 * seller's contact block goes through Sellers' own SellerContact, which means
 * a guest gets labels and a masked shape here for exactly the same reason
 * they do on the seller's page.
 *
 * @mixin Product
 */
class ProductDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            ...ProductCardResource::make($this->resource)->resolve($request),

            'description' => $this->description,
            'warranty_text' => $this->warranty_text,
            'part_number' => $this->part_number,
            'oem_number' => $this->oem_number,
            'sourcing' => [
                'value' => $this->sourcing->value,
                'label' => $this->sourcing->label(),
            ],

            'specification' => $this->specification(),
            'photos' => $this->photos(),
            'video' => $this->video(),

            'variants' => ProductVariantResource::collection($this->variants)->resolve($request),

            /*
             * Which options this buyer has already asked to be told about.
             * Read once for the whole listing rather than per variant — a
             * five-option listing should not be five queries — and empty for
             * a guest, who has nowhere for a notification to go.
             */
            'stock_alerts' => $this->stockAlerts($viewer),

            'seller' => $this->whenLoaded('seller', fn (): array => [
                'id' => $this->seller->id,
                'slug' => $this->seller->slug,
                'business_name' => $this->seller->business_name,
                'type_label' => $this->seller->type->label(),
                'verified' => $this->seller->isVerified(),
                'verification_label' => $this->seller->verification_status->publicLabel(),
                'location' => $this->seller->relationLoaded('city') ? $this->seller->singleLine() : null,
                /*
                 * Masked on the server for a guest. A CSS blur over the real
                 * number is not privacy — it is still in the page source.
                 */
                'contact' => SellerContact::for($this->seller, $viewer instanceof User ? $viewer : null)->toArray(),
                'policies' => $this->seller->relationLoaded('currentPolicies')
                    ? SellerPolicyResource::collection($this->seller->currentPolicies)->resolve($request)
                    : [],
                'rating' => ['average' => null, 'count' => 0],
            ]),

            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }

    /**
     * The spec table, with the rows the seller left blank dropped.
     *
     * A table of empty cells reads as missing information rather than as
     * information that does not apply, so an unanswered field is simply not
     * a row.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function specification(): array
    {
        $rows = [
            'Fits' => $this->fitmentSummary(),
            'Body type' => $this->body_type?->label(),
            'Engine size' => $this->engine_size_cc === null ? null : number_format($this->engine_size_cc).' cc',
            'Engine code' => $this->engine_code,
            'Fuel type' => $this->fuel_type?->label(),
            'Transmission' => $this->transmission?->label(),
            'Drive type' => $this->drive_type?->label(),
            'Trim' => $this->trim,
            'Part number' => $this->part_number,
            'OEM number' => $this->oem_number,
            'Chassis compatibility' => $this->chassis_compatibility,
        ];

        $filled = array_filter($rows, static fn (?string $value): bool => filled($value));

        return array_map(
            static fn (string $label, string $value): array => ['label' => $label, 'value' => $value],
            array_keys($filled),
            array_values($filled),
        );
    }

    /**
     * The gallery. Only conversions are served — the seller's original stays
     * on the private disk, EXIF and all.
     *
     * @return array<int, array{id: int, thumb: string, card: string, web: string, alt: string}>
     */
    private function photos(): array
    {
        return $this->getMedia(Product::PHOTOS_COLLECTION)
            ->map(fn (Media $media, int $index): array => [
                'id' => $media->getKey(),
                'thumb' => $media->getUrl('thumb'),
                'card' => $media->getUrl('card'),
                'web' => $media->getUrl('web'),
                'alt' => sprintf('%s, photo %d', $this->name, $index + 1),
            ])
            ->values()
            ->all();
    }

    /**
     * The walk-round clip, once the transcode job has produced one.
     *
     * @return array{poster: string|null, source: string|null}|null
     */
    private function video(): ?array
    {
        $media = $this->getFirstMedia(Product::VIDEO_COLLECTION);

        if ($media === null) {
            return null;
        }

        $poster = $media->getCustomProperty('video.poster');
        $source = $media->getCustomProperty('video.web');

        return [
            'poster' => is_string($poster) ? $this->conversionUrl($media, $poster) : null,
            'source' => is_string($source) ? $this->conversionUrl($media, $source) : null,
        ];
    }

    /**
     * The variant ids this viewer is waiting on.
     *
     * @return array<int, int>
     */
    private function stockAlerts(mixed $viewer): array
    {
        if (! $viewer instanceof User) {
            return [];
        }

        return BackInStockSubscription::query()
            ->where('user_id', $viewer->getKey())
            ->where('product_id', $this->id)
            ->pending()
            ->pluck('product_variant_id')
            ->map(static fn (int $id): int => $id)
            ->all();
    }

    private function conversionUrl(Media $media, string $relativePath): string
    {
        return Storage::disk($media->conversions_disk ?? $media->disk)->url($relativePath);
    }
}
