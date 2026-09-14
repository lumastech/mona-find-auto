<?php

namespace App\Providers;

use App\Support\Console\ConsoleCounters;
use App\Support\Content\ContentScreen;
use App\Support\Reference\ReferenceMerger;
use App\Support\Reference\ReferenceRegistry;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\ExceptionResponse;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * The automatic screen over free text. Shared kernel rather than a
         * module service: reviews and messages both run text through it, and
         * what a Zambian phone number looks like should have one definition.
         */
        $this->app->singleton(ContentScreen::class);

        /*
         * Two registries the staff console reads and every module writes to.
         * Both are singletons because a module contributes to them from its
         * own service provider, and a fresh instance per resolution would
         * hand the console an empty one.
         */
        $this->app->singleton(ConsoleCounters::class);
        $this->app->singleton(ReferenceRegistry::class);
        $this->app->singleton(ReferenceMerger::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureOpenApi();
        $this->configureErrorPages();
    }

    /**
     * Missing, forbidden and broken pages, inside the storefront shell.
     *
     * A 404 never reaches a route, so it never reaches the Inertia
     * middleware either — `withSharedData()` is what resolves that middleware
     * anyway, and without it the error page would render with no header, no
     * menu and no cart count. A visitor who followed a dead link to a sold
     * listing should land somewhere they can search from.
     *
     * Left alone in local and testing, where the exception page itself is the
     * more useful answer.
     */
    protected function configureErrorPages(): void
    {
        if ($this->app->environment(['local', 'testing'])) {
            return;
        }

        Inertia::handleExceptionsUsing(function (ExceptionResponse $response): ?ExceptionResponse {
            if (! in_array($response->statusCode(), [403, 404, 500, 503], strict: true)) {
                return null;
            }

            return $response
                ->render('storefront/Error', ['status' => $response->statusCode()])
                ->withSharedData();
        });
    }

    /**
     * Describe how the JSON API is authenticated in the generated OpenAPI
     * document.
     *
     * Scramble is a development dependency, so this is a no-op in production
     * where the document is a build artifact rather than something generated
     * on the fly.
     */
    protected function configureOpenApi(): void
    {
        if (! class_exists(Scramble::class)) {
            return;
        }

        Scramble::configure()->withDocumentTransformers(
            fn (OpenApi $document) => $document->secure(SecurityScheme::http('bearer')),
        );
    }

    /**
     * The platform's named rate limits.
     *
     * ## Counted per account wherever there is one
     *
     * Zambian mobile networks put a great many subscribers behind a small
     * number of egress addresses, and an office shares one. Keying a limit on
     * the IP alone would mean one buyer's retry loop throttling a whole
     * network's worth of strangers. Every limiter below therefore counts an
     * authenticated request against the account and falls back to the address
     * only for people who have not signed in yet — where the address is all
     * there is, and where the traffic worth limiting lives anyway.
     *
     * ## The auth limiters live in FortifyServiceProvider
     *
     * `login`, `two-factor` and `passkeys` are Fortify's own limiter names and
     * are defined next to the rest of Fortify's configuration. The OTP and
     * password-reset routes carry inline throttles at their declaration,
     * because each attempt costs an SMS and the number belongs next to the
     * route that sends it.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(60)
            ->by($this->rateLimitKey($request)));

        /*
         * Placing an order. Tight, because a checkout POST reserves stock,
         * snapshots a monetisation policy and writes an order group — it is
         * the most expensive thing a buyer can ask for, and nobody legitimately
         * places ten orders a minute.
         */
        RateLimiter::for('checkout', fn (Request $request): Limit => Limit::perMinute(10)
            ->by($this->rateLimitKey($request)));

        /*
         * Paying for one. Looser than checkout on purpose: a buyer whose
         * mobile-money PIN times out will legitimately try again several
         * times, and the status page polls itself while they wait.
         */
        RateLimiter::for('payments', fn (Request $request): Limit => Limit::perMinute(30)
            ->by($this->rateLimitKey($request)));

        /*
         * Gateway callbacks. Generous enough never to throttle Lenco's real
         * traffic — including a redelivery burst after an outage — and tight
         * enough that an unsigned flood cannot cost a database write each.
         *
         * Keyed on the address rather than the account: a webhook has no
         * session and never will.
         */
        RateLimiter::for('webhooks', fn (Request $request): Limit => Limit::perMinute(240)
            ->by($request->ip()));

        /*
         * Search. Guests browse everything, so this is the busiest unauthenticated
         * surface on the platform and the one a scraper reaches for first.
         */
        RateLimiter::for('search', fn (Request $request): Limit => Limit::perMinute(90)
            ->by($this->rateLimitKey($request)));

        /*
         * Creating accounts. The captcha is the real defence; this is the
         * backstop for the case where it is failing open during an outage.
         */
        RateLimiter::for('register', fn (Request $request): Limit => Limit::perMinutes(10, 10)
            ->by($request->ip()));

        /*
         * Uploads. Counted per account and deliberately low — each one costs
         * a media conversion on the queue, so a runaway client here is felt
         * by every seller waiting for their photos.
         */
        RateLimiter::for('uploads', fn (Request $request): Limit => Limit::perMinute(30)
            ->by($this->rateLimitKey($request)));
    }

    /**
     * The account if there is one, the address otherwise.
     *
     * Prefixed so that a user id and an address can never collide into the
     * same bucket — user 5 and the address "5" are not the same client.
     */
    protected function rateLimitKey(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? 'user:'.$id : 'ip:'.$request->ip();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        /*
         * The N+1 detector.
         *
         * Laravel's own, rather than a package: a lazy load is precisely what
         * an N+1 is, and the framework already knows when one happens. It
         * throws everywhere except production — so CI catches the query in
         * the pull request that introduced it, while a buyer mid-checkout
         * never sees a 500 over a missing `with()`.
         *
         * A relation that genuinely should be loaded on demand is loaded with
         * `loadMissing()`, which is explicit and does not trip this.
         *
         * `preventSilentlyDiscardingAttributes` is the same bargain for a
         * different bug: a `fill()` carrying a key that is not fillable
         * currently vanishes without trace, which is how a "why did that
         * column not save" afternoon starts.
         */
        Model::preventLazyLoading(! app()->isProduction());
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
