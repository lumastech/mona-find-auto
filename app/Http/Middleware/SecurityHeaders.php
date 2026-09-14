<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers that decide what a MonaFind page is allowed to do.
 *
 * ## The nonce has to be minted before the response renders
 *
 * `Vite::useCspNonce()` both generates the nonce and makes Laravel stamp it
 * onto every tag the `@vite` directive emits. That has to happen before Blade
 * runs, which is why it is on the way in rather than on the way out. The one
 * hand-written inline script in app.blade.php reads the same nonce through
 * `Vite::cspNonce()`.
 *
 * ## Why style-src still allows inline
 *
 * Scripts and styles are not the same risk. An injected script runs; an
 * injected style can at worst reposition something or, with an attribute
 * selector, exfiltrate the value of a form field it can already see. Vue
 * writes `style` attributes from `:style` bindings on nearly every page and
 * SSR renders them into the HTML, so a nonce-only style policy would mean
 * auditing every binding in the application for a fraction of the benefit.
 * Scripts get the nonce, which is where the real protection is.
 *
 * ## Frames
 *
 * `frame-ancestors 'none'` is the modern X-Frame-Options and stops MonaFind
 * being framed for a clickjacking overlay. `frame-src` is the other
 * direction — the Lenco widget and the Turnstile challenge are iframes we
 * embed, and both hosts have to be named for them to load at all.
 *
 * ## Responses this skips
 *
 * A CSP on a JSON body protects nothing and costs bytes on every API call, so
 * only HTML responses carry the policy. The transport and framing headers go
 * on everything, because a JSON endpoint served over plain HTTP is as much a
 * problem as a page.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->cspEnabled()) {
            Vite::useCspNonce();
        }

        $response = $next($request);

        $this->applyTransportHeaders($request, $response);

        if ($this->isHtml($response)) {
            $this->applyDocumentHeaders($response);
        }

        return $response;
    }

    /**
     * Headers that belong on every response, HTML or not.
     */
    private function applyTransportHeaders(Request $request, Response $response): void
    {
        /*
         * Stops a browser from second-guessing a Content-Type — the trick that
         * turns an uploaded "image" the server calls image/png into a script
         * because its first bytes look like HTML.
         */
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        /*
         * Origin only, so a seller's dashboard URL — which carries ids — is
         * never handed to a third party in a Referer header, while ordinary
         * same-site navigation still works.
         */
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        /*
         * Keeps a popup from reaching back into the page that opened it. The
         * Lenco widget and the social sign-in flow both open windows.
         */
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->secure() && (bool) config('security.hsts.enabled', true)) {
            $response->headers->set('Strict-Transport-Security', $this->hsts());
        }
    }

    /**
     * Headers that only mean something on a rendered page.
     */
    private function applyDocumentHeaders(Response $response): void
    {
        $response->headers->set('X-Frame-Options', 'DENY');

        $policy = config('security.permissions_policy');

        if (is_string($policy) && $policy !== '') {
            $response->headers->set('Permissions-Policy', $policy);
        }

        if (! $this->cspEnabled()) {
            return;
        }

        $header = config('security.csp.report_only', false)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($header, $this->contentSecurityPolicy());
    }

    /**
     * Assemble the policy from config, the per-request nonce, and — in a dev
     * environment running Vite — the hot server the assets come from.
     */
    private function contentSecurityPolicy(): string
    {
        $nonce = Vite::cspNonce();
        $nonceSource = $nonce !== null ? "'nonce-{$nonce}'" : "'self'";

        $directives = [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            /* We are never framed. */
            'frame-ancestors' => ["'none'"],
            'object-src' => ["'none'"],
            'script-src' => ["'self'", $nonceSource, ...$this->allowed('script')],
            'style-src' => ["'self'", "'unsafe-inline'", ...$this->allowed('style')],
            'font-src' => ["'self'", 'data:', ...$this->allowed('font')],
            /*
             * blob: covers the client-side preview a seller sees before a
             * listing photo has finished uploading.
             */
            'img-src' => ["'self'", 'data:', 'blob:', ...$this->allowed('image')],
            'media-src' => ["'self'", 'blob:'],
            'connect-src' => ["'self'", ...$this->allowed('connect')],
            'frame-src' => ["'self'", ...$this->allowed('frame')],
            'worker-src' => ["'self'", 'blob:'],
            'manifest-src' => ["'self'"],
        ];

        /*
         * Vite in dev serves modules and opens a websocket for hot reload from
         * its own origin. Without this the policy would make `npm run dev`
         * unusable for anyone who switched CSP on locally to test it.
         */
        if ($hot = $this->viteDevServer()) {
            $directives['script-src'][] = $hot;
            $directives['style-src'][] = $hot;
            $directives['connect-src'][] = $hot;
            $directives['connect-src'][] = str_replace(['http://', 'https://'], ['ws://', 'wss://'], $hot);
        }

        if (is_string($reportUri = config('security.csp.report_uri')) && $reportUri !== '') {
            $directives['report-uri'] = [$reportUri];
        }

        return implode('; ', array_map(
            static fn (string $directive, array $sources): string => $directive.' '.implode(' ', array_unique($sources)),
            array_keys($directives),
            $directives,
        ));
    }

    /**
     * Every configured host for one source type, flattened across the
     * integration groups that declared them.
     *
     * @return array<int, string>
     */
    private function allowed(string $type): array
    {
        $groups = config('security.csp.allow', []);

        if (! is_array($groups)) {
            return [];
        }

        $hosts = [];

        foreach ($groups as $group) {
            if (! is_array($group) || ! isset($group[$type]) || ! is_array($group[$type])) {
                continue;
            }

            foreach ($group[$type] as $host) {
                $hosts[] = (string) $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * The Vite dev server's origin, or null when assets are built.
     */
    private function viteDevServer(): ?string
    {
        if (! Vite::isRunningHot()) {
            return null;
        }

        $hot = @file_get_contents(public_path('hot'));

        if (! is_string($hot)) {
            return null;
        }

        $origin = trim($hot);

        return $origin !== '' ? rtrim($origin, '/') : null;
    }

    private function hsts(): string
    {
        $value = 'max-age='.(int) config('security.hsts.max_age', 31536000);

        if ((bool) config('security.hsts.include_subdomains', true)) {
            $value .= '; includeSubDomains';
        }

        if ((bool) config('security.hsts.preload', false)) {
            $value .= '; preload';
        }

        return $value;
    }

    private function cspEnabled(): bool
    {
        return (bool) config('security.csp.enabled', false);
    }

    private function isHtml(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type', ''), 'text/html');
    }
}
