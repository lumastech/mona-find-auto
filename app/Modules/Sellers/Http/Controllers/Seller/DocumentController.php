<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Controllers\Seller;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Modules\Sellers\Concerns\ResolvesCurrentSeller;
use App\Modules\Sellers\Http\Requests\Seller\SellerDocumentRequest;
use App\Modules\Sellers\Services\SellerDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * A seller's verification documents.
 *
 * Uploads go to the private disk. Nothing here ever produces a URL — even the
 * seller reads their own documents back through the streaming route.
 */
class DocumentController extends Controller
{
    use InteractsWithCurrentUser, ResolvesCurrentSeller;

    public function __construct(private readonly SellerDocumentService $documents) {}

    public function index(Request $request): Response
    {
        $seller = $this->currentSeller($request);

        return Inertia::render('seller/Documents', [
            'documents' => $this->documents->summarise($seller),
            'missing' => array_map(
                static fn ($type): string => $type->label(),
                $seller->missingDocuments(),
            ),
        ]);
    }

    public function store(SellerDocumentRequest $request): RedirectResponse
    {
        $seller = $this->currentSeller($request);

        $this->documents->attach($seller, $request->documentType(), $request->document(), $this->currentUser($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':document uploaded.', ['document' => $request->documentType()->label()]),
        ]);

        return back();
    }

    public function destroy(Request $request, Media $media): RedirectResponse
    {
        $seller = $this->currentSeller($request);

        abort_unless(
            $media->model_type === $seller->getMorphClass() && $media->model_id === $seller->getKey(),
            HttpResponse::HTTP_NOT_FOUND,
        );

        $this->documents->remove($seller, $media, $this->currentUser($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Document removed.')]);

        return back();
    }
}
