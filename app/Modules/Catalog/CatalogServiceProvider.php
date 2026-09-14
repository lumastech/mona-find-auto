<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Modules\Catalog\Contracts\VideoProcessor;
use App\Modules\Catalog\Events\ListingStatusChanged;
use App\Modules\Catalog\Listeners\NotifySellerOfModeration;
use App\Modules\Catalog\Listeners\UnpublishListingsOfSuspendedSeller;
use App\Modules\Catalog\Listeners\WatermarkListingImage;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Make;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\VehicleModel;
use App\Modules\Catalog\Policies\ProductPolicy;
use App\Modules\Catalog\Policies\ReferenceDataPolicy;
use App\Modules\Catalog\Services\CatalogConsoleCounters;
use App\Modules\Catalog\Services\StorefrontNavigation;
use App\Modules\Catalog\Support\ListingWatermarker;
use App\Modules\Catalog\Support\Video\FfmpegVideoProcessor;
use App\Modules\Catalog\Support\Video\NullVideoProcessor;
use App\Modules\Sellers\Events\SellerVerificationChanged;
use App\Support\Console\ConsoleCounters;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Reference\ReferenceList;
use App\Support\Reference\ReferenceRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;

/**
 * Catalog module — reference data, listings, media and moderation.
 *
 * Everything a buyer looks at before they decide to order lives here: the
 * make and category lists sellers pick from, the listing itself with its two
 * independent badges, the media pipeline that turns a seller's phone
 * photograph into a watermarked WebP, and the moderation workflow that
 * decides whether any of it reaches the storefront.
 *
 * Its only dependency on another module's workflow is one event: when a shop
 * is suspended, its stock comes down with it.
 */
class CatalogServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->singleton(ListingWatermarker::class, fn (): ListingWatermarker => ListingWatermarker::default());

        /*
         * ffmpeg is an external binary that is simply not there on some
         * boxes — CI above all. Where it is missing the null processor keeps
         * the seller's original video and skips the transcode, rather than
         * failing an upload over a missing dependency.
         */
        $this->app->singleton(VideoProcessor::class, function (): VideoProcessor {
            if (! config('catalog.video.transcode_enabled', true)) {
                return new NullVideoProcessor;
            }

            $processor = new FfmpegVideoProcessor(
                ffmpeg: (string) config('catalog.video.ffmpeg_binary', 'ffmpeg'),
                ffprobe: (string) config('catalog.video.ffprobe_binary', 'ffprobe'),
            );

            return $processor->isAvailable() ? $processor : new NullVideoProcessor;
        });
    }

    protected function bootModule(): void
    {
        $this->app->make(ConsoleCounters::class)->register(CatalogConsoleCounters::class);

        $this->registerReferenceLists();

        $this->registerPolicies();
        $this->registerListeners();
        $this->shareNavigation();
    }

    /**
     * The header's category menu and vehicle picker, on every storefront page.
     *
     * Shared rather than passed per page because the header is on every
     * screen: a menu that is only present on the pages whose controller
     * remembered to send it is a menu that disappears, which is worse than
     * not having one. Only inside the storefront — the seller portal and the
     * staff console never resolve the closure.
     */
    private function shareNavigation(): void
    {
        Inertia::share('nav', function (Request $request): ?array {
            if ($request->is('seller', 'seller/*', 'admin', 'admin/*')) {
                return null;
            }

            return $this->app->make(StorefrontNavigation::class)->payload();
        });
    }

    private function registerPolicies(): void
    {
        Gate::policy(Product::class, ProductPolicy::class);

        /*
         * The three reference lists answer the same question — is this staff
         * curating the platform's own vocabulary? — so one policy governs all
         * of them rather than three identical classes.
         */
        Gate::policy(Category::class, ReferenceDataPolicy::class);
        Gate::policy(Make::class, ReferenceDataPolicy::class);
        Gate::policy(VehicleModel::class, ReferenceDataPolicy::class);
    }

    private function registerListeners(): void
    {
        /* Suspending a shop takes its stock down with it. */
        Event::listen(SellerVerificationChanged::class, UnpublishListingsOfSuspendedSeller::class);

        /* Moderation outcomes reach the shop that is waiting on them. */
        Event::listen(ListingStatusChanged::class, NotifySellerOfModeration::class);

        /* The wordmark is burnt into each display conversion as it is generated. */
        Event::listen(ConversionHasBeenCompletedEvent::class, WatermarkListingImage::class);

        /*
         * The header menu is cached for a day, so curating reference data has
         * to knock it down. Hung off the models rather than the three admin
         * controllers because the reference merger writes these rows too, and
         * a merged make that lingers in the menu for a day is a dead link.
         */
        $flush = function (Model $written): void {
            $this->app->make(StorefrontNavigation::class)->flush();
        };

        foreach ([Category::class, Make::class, VehicleModel::class] as $model) {
            $model::saved($flush);
            $model::deleted($flush);
        }
    }

    /**
     * The three lists this module curates, and everything pointing at them.
     *
     * Declared rather than hard-coded into the console so that the merge tool
     * finds every pointer without Admin knowing what a listing is. The links
     * below are Catalog's own columns only — another module referencing a
     * make declares that itself.
     */
    private function registerReferenceLists(): void
    {
        $references = $this->app->make(ReferenceRegistry::class);

        $references->register(new ReferenceList(
            key: 'makes',
            label: 'Vehicle makes',
            singular: 'make',
            model: Make::class,
            owner: 'Catalog',
            detailRoute: 'admin.reference.index',
            note: 'What stops Toyota, TOYOTA and Toyata becoming three marques.',
        ));

        $references->register(new ReferenceList(
            key: 'vehicle-models',
            label: 'Vehicle models',
            singular: 'model',
            model: VehicleModel::class,
            owner: 'Catalog',
            detailRoute: 'admin.reference.index',
            parentKey: 'makes',
            parentColumn: 'make_id',
            note: 'Scoped to a make; merging moves the listings fitted to it.',
        ));

        $references->register(new ReferenceList(
            key: 'categories',
            label: 'Part categories',
            singular: 'category',
            model: Category::class,
            owner: 'Catalog',
            detailRoute: 'admin.reference.index',
            parentKey: 'categories',
            parentColumn: 'parent_id',
            note: 'The three-level tree buyers browse and sellers file against.',
        ));

        $references->link('makes', 'vehicle_models', 'make_id');
        $references->link('makes', 'products', 'make_id');
        $references->link('vehicle-models', 'products', 'vehicle_model_id');
        $references->link('categories', 'categories', 'parent_id');
        $references->link('categories', 'products', 'category_id');
    }
}
