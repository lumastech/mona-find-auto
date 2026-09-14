<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Services;

use App\Models\User;
use App\Modules\Sellers\Enums\DocumentType;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The paperwork a seller uploads to be verified.
 *
 * Documents hold NRC numbers, addresses and company records, so they live on
 * the private disk and are never given a public URL. Staff read them through
 * a policy-checked streaming route; nothing here ever produces a link.
 */
class SellerDocumentService
{
    /**
     * Attach a document, replacing any previous upload of the same type.
     *
     * Replacing rather than accumulating is deliberate: a reviewer looking at
     * three certificates of incorporation cannot tell which one is current,
     * and the seller re-uploading is nearly always a correction.
     */
    public function attach(Seller $seller, DocumentType $type, UploadedFile $file, ?User $actor = null): Media
    {
        $this->removeExisting($seller, $type);

        $media = $seller->addMedia($file->getRealPath())
            ->usingName($type->label())
            ->usingFileName($this->fileNameFor($seller, $type, $file))
            ->withCustomProperties(['document_type' => $type->value])
            ->toMediaCollection('documents');

        audit(
            $actor,
            'seller.document.uploaded',
            $seller,
            null,
            ['document_type' => $type->value, 'media_id' => $media->getKey(), 'file_name' => $media->file_name],
            null,
            ['seller_id' => $seller->getKey()],
        );

        return $media;
    }

    /**
     * Remove a document. Staff and the seller can both do this; both are
     * audited, because a document disappearing between review sessions is
     * exactly the kind of thing somebody later needs explained.
     */
    public function remove(Seller $seller, Media $media, ?User $actor = null): void
    {
        audit(
            $actor,
            'seller.document.removed',
            $seller,
            ['document_type' => $media->getCustomProperty('document_type'), 'media_id' => $media->getKey()],
            null,
            null,
            ['seller_id' => $seller->getKey()],
        );

        $media->delete();
    }

    /**
     * What the wizard, the seller's own document list and the reviewer's
     * viewer all render: every document type this business needs, with the
     * upload against it when there is one.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summarise(Seller $seller): array
    {
        $required = array_map(static fn (DocumentType $type): string => $type->value, $seller->type->requiredDocuments());
        $uploads = $seller->getMedia('documents')->keyBy(
            static fn (Media $media): string => (string) $media->getCustomProperty('document_type'),
        );

        return array_map(function (array $option) use ($required, $uploads): array {
            $media = $uploads->get($option['value']);

            return [
                ...$option,
                'required' => in_array($option['value'], $required, true),
                'uploaded' => $media !== null,
                'media_id' => $media?->getKey(),
                'file_name' => $media?->file_name,
                'size' => $media?->size,
                'uploaded_at' => $media?->created_at?->toIso8601String(),
            ];
        }, DocumentType::options());
    }

    /**
     * Drop whatever is already filed under this type.
     *
     * Queried rather than read off `$seller->media`, because that relation is
     * cached on the model: two uploads in one request would otherwise see the
     * same stale collection and both survive.
     */
    private function removeExisting(Seller $seller, DocumentType $type): void
    {
        $seller->media()
            ->where('collection_name', 'documents')
            ->get()
            ->filter(static fn (Media $media): bool => $media->getCustomProperty('document_type') === $type->value)
            ->each(static fn (Media $media) => $media->delete());

        $seller->unsetRelation('media');
    }

    /**
     * A predictable name so a reviewer downloading three files can tell them
     * apart in their downloads folder.
     */
    private function fileNameFor(Seller $seller, DocumentType $type, UploadedFile $file): string
    {
        return sprintf('%s-%s.%s', $seller->slug, str_replace('_', '-', $type->value), $file->getClientOriginalExtension());
    }
}
