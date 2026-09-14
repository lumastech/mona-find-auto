<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A listing as its own seller sees it in the portal.
 *
 * The difference from the storefront shape is what it adds: where the listing
 * is in its lifecycle, what a moderator said about it field by field, and
 * whether the seller may still edit it. The inspection badge is here too but
 * read-only — it is MonaFind's claim about the part, not the seller's.
 *
 * @mixin Product
 */
class SellerProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,

            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'guidance' => $this->status->sellerGuidance(),
                'variant' => $this->status->badgeVariant(),
                'editable' => $this->status->isEditableBySeller(),
                'live' => $this->status->isVisibleToBuyers(),
            ],

            /* The two badges, exactly as the storefront renders them. */
            'condition' => [
                'value' => $this->condition->value,
                'label' => $this->condition->label(),
                'variant' => $this->condition->badgeVariant(),
                /* True for a car breaker: the condition is decided by the shop, not the form. */
                'locked' => $this->seller->type->sellsBreakerStock(),
            ],
            'inspection' => [
                'value' => $this->inspection_status->value,
                'label' => $this->inspection_status->label(),
                'description' => $this->inspection_status->description(),
                'variant' => $this->inspection_status->badgeVariant(),
                'inspected' => $this->inspection_status->isInspected(),
                /* Staff-only, always. Shown here so the seller knows where it stands. */
                'editable' => false,
            ],

            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn (): string => $this->category->name),
            'make_id' => $this->make_id,
            'vehicle_model_id' => $this->vehicle_model_id,
            'year_from' => $this->year_from,
            'year_to' => $this->year_to,
            'sourcing' => $this->sourcing->value,
            'part_number' => $this->part_number,
            'oem_number' => $this->oem_number,
            'engine_size_cc' => $this->engine_size_cc,
            'engine_code' => $this->engine_code,
            'fuel_type' => $this->fuel_type?->value,
            'transmission' => $this->transmission?->value,
            'drive_type' => $this->drive_type?->value,
            'body_type' => $this->body_type?->value,
            'trim' => $this->trim,
            'chassis_compatibility' => $this->chassis_compatibility,
            'warranty_text' => $this->warranty_text,
            'delivery_available' => $this->delivery_available,

            'variants' => ProductVariantResource::collection($this->whenLoaded('variants'))->resolve($request),
            'photos' => $this->photos(),
            'video' => $this->videoSummary(),

            /* What the moderator said, so the seller can fix it field by field. */
            'rejection_reason' => $this->rejection_reason,
            'rejection_fields' => $this->rejection_fields ?? [],

            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array{id: int, thumb: string, card: string, name: string|null}>
     */
    private function photos(): array
    {
        return $this->getMedia(Product::PHOTOS_COLLECTION)
            ->map(static fn (Media $media): array => [
                'id' => $media->getKey(),
                'thumb' => $media->getUrl('thumb'),
                'card' => $media->getUrl('card'),
                'name' => $media->file_name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, name: string, processed: bool}|null
     */
    private function videoSummary(): ?array
    {
        $media = $this->getFirstMedia(Product::VIDEO_COLLECTION);

        if ($media === null) {
            return null;
        }

        return [
            'id' => $media->getKey(),
            'name' => $media->file_name,
            /* False while the transcode job is still working through the queue. */
            'processed' => is_string($media->getCustomProperty('video.web')),
        ];
    }
}
