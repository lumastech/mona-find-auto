<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cache headers for the pages guests and crawlers read.
 *
 * ## Signed in means private, always
 *
 * A logged-in page carries the buyer's name, their cart count and — on a
 * listing — the seller's phone number, which guests are not shown. Letting
 * any cache hold that is how one buyer is served another's header. So an
 * authenticated response is `private, no-store`, with no per-route opt-in
 * that somebody could get wrong.
 *
 * ## Why guest pages are `private` too, by default
 *
 * This is the decision worth reading, because the obvious implementation is
 * wrong.
 *
 * Laravel starts a session for every visitor, guests included, and issues a
 * session cookie and an XSRF token with it. A response marked `public` is
 * eligible to be stored by a shared cache and handed to somebody else — and
 * it would be handed over complete with the `Set-Cookie` that ties it to the
 * first visitor's session. Adding `Vary: Cookie` makes that safe and makes
 * the cache useless in the same stroke, because every guest carries a
 * different session cookie and therefore misses.
 *
 * So the default is `private` with a browser-level `max-age`: a real win for
 * back-button navigation and repeat views, and safe without any assumption
 * about infrastructure we do not control.
 *
 * `s-maxage` is available and OFF unless a deployment sets
 * `STOREFRONT_SHARED_CACHE_SECONDS`. Turning it on is a promise that the CDN
 * in front of MonaFind strips session cookies from guest requests and
 * refuses to store responses carrying `Set-Cookie`. That promise belongs to
 * whoever configures the CDN, which is why it is a deliberate switch and a
 * line on docs/LAUNCH.md rather than a default.
 *
 * ## Keep the window short whatever it is set to
 *
 * Listings change: a seller confirms stock, a price moves, something sells
 * out. A ten-minute cache would send a buyer across Lusaka for a part that
 * went this morning. `stale-while-revalidate` covers the refresh so the
 * first visitor after expiry is not the one who waits for it.
 */
class CacheStorefrontResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->isPublic($request, $response)) {
            return $this->markPrivate($response);
        }

        $directives = ['max-age='.$this->browserSeconds()];

        $shared = $this->sharedSeconds();

        if ($shared > 0) {
            /* See the class docblock: this is a promise about the CDN. */
            $directives[] = 's-maxage='.$shared;
            $directives[] = 'stale-while-revalidate='.$this->staleSeconds();
            array_unshift($directives, 'public');
        } else {
            array_unshift($directives, 'private');
        }

        $response->headers->set('Cache-Control', implode(', ', $directives));

        /*
         * The SSR document and an Inertia partial share a URL and differ only
         * by this header. Getting Vary wrong here serves a JSON payload to
         * somebody who asked for a page.
         */
        $this->varyOn($response, ['Accept', 'Accept-Encoding', 'X-Inertia']);

        return $response;
    }

    /**
     * Merge field names into a single `Vary` header.
     *
     * Written by hand rather than with `setVary()`, which emits one header
     * line per field — legal HTTP, but it means `get('Vary')` returns only
     * the first and every reader has to know to ask for all of them.
     * Inertia's own middleware also sets `Vary`, so existing values are kept
     * rather than replaced: dropping `X-Inertia` would let a cache serve a
     * JSON partial to a browser that asked for a document.
     *
     * @param  array<int, string>  $fields
     */
    private function varyOn(Response $response, array $fields): void
    {
        $existing = $response->headers->all('Vary');

        $merged = [];

        foreach ([...$existing, ...$fields] as $value) {
            foreach (explode(',', (string) $value) as $field) {
                $field = trim($field);

                if ($field !== '' && ! in_array($field, $merged, true)) {
                    $merged[] = $field;
                }
            }
        }

        $response->headers->set('Vary', implode(', ', $merged));
    }

    /**
     * Is this a page that is the same for every signed-out visitor?
     */
    private function isPublic(Request $request, Response $response): bool
    {
        return $request->isMethod('GET')
            && $request->user() === null
            && $response->getStatusCode() === Response::HTTP_OK
            && ! $this->carriesFlashState();
    }

    /**
     * Is this page rendering validation errors or old input?
     *
     * Read off the view-shared error bag rather than the session. This
     * middleware unwinds LAST — that is what makes its `Vary` header survive
     * Inertia's — and by then `StartSession` has aged the flash out of the
     * session, so asking the session would always answer no.
     *
     * `ShareErrorsFromSession` puts the bag on the view factory at the start
     * of the request and it is still there at the end, which makes it the
     * one reliable signal available at this point in the stack.
     */
    private function carriesFlashState(): bool
    {
        $errors = view()->shared('errors');

        return $errors instanceof ViewErrorBag && $errors->any();
    }

    private function markPrivate(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    private function browserSeconds(): int
    {
        return max(0, (int) config('security.cache.browser_seconds', 30));
    }

    private function sharedSeconds(): int
    {
        return max(0, (int) config('security.cache.shared_seconds', 0));
    }

    private function staleSeconds(): int
    {
        return max(0, (int) config('security.cache.stale_seconds', 300));
    }
}
