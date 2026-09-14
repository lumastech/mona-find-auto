<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The reviewer's document viewer.
 *
 * Documents hold NRC numbers, addresses and company records, so they live on
 * the private disk and have no URL of their own. This route is the only way
 * to read one, and every read goes through the policy first.
 */
class SellerDocumentController extends Controller
{
    /**
     * Stream one document inline, so a reviewer reads it in the browser
     * rather than accumulating copies in their downloads folder.
     */
    public function show(Request $request, Seller $seller, Media $media): StreamedResponse
    {
        Gate::authorize('viewDocuments', $seller);

        /*
         * Media ids are global, so a document is only readable through the
         * seller it actually belongs to. Without this check any staff member
         * could read any seller's file through any seller's URL.
         */
        abort_unless(
            $media->model_type === $seller->getMorphClass()
                && $media->model_id === $seller->getKey()
                && $media->collection_name === 'documents',
            HttpResponse::HTTP_NOT_FOUND,
        );

        audit(
            $request->user(),
            'seller.document.viewed',
            $seller,
            null,
            ['media_id' => $media->getKey(), 'document_type' => $media->getCustomProperty('document_type')],
        );

        return response()->stream(
            static function () use ($media): void {
                $stream = $media->stream();

                while (! feof($stream)) {
                    echo fread($stream, 8192);
                    flush();
                }

                fclose($stream);
            },
            HttpResponse::HTTP_OK,
            [
                'Content-Type' => $media->mime_type,
                'Content-Disposition' => 'inline; filename="'.$media->file_name.'"',
                /* Never cached by a proxy: this is somebody's ID document. */
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }
}
