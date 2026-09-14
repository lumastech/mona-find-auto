<?php

declare(strict_types=1);

use App\Models\User;

/**
 * Switch the policy on. It is off in `testing` by default, for the same
 * reason it is off in `local`: a nonce changes per request and most of the
 * suite has no opinion about headers.
 */
function enforcingCsp(): void
{
    config()->set('security.csp.enabled', true);
    config()->set('security.csp.report_only', false);
}

it('puts the transport headers on every response, JSON included', function (): void {
    $this->get(route('home'))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');

    $this->getJson(route('api.v1.platform'))
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('refuses to be framed', function (): void {
    enforcingCsp();

    $response = $this->get(route('home'))->assertHeader('X-Frame-Options', 'DENY');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("frame-ancestors 'none'");
});

it('sends a content security policy with a per-request nonce', function (): void {
    enforcingCsp();

    $first = $this->get(route('home'))->headers->get('Content-Security-Policy');
    $second = $this->get(route('home'))->headers->get('Content-Security-Policy');

    expect($first)->toMatch("/script-src [^;]*'nonce-[A-Za-z0-9+\/=]+'/")
        /* A nonce reused across requests is no better than 'unsafe-inline'. */
        ->and($first)->not->toBe($second);
});

it('stamps the same nonce onto the one inline script in the layout', function (): void {
    enforcingCsp();

    $response = $this->get(route('home'));

    $policy = (string) $response->headers->get('Content-Security-Policy');

    preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", $policy, $matches);

    expect($matches[1] ?? null)->not->toBeNull();

    /*
     * Without this the dark-mode script is blocked and every page loads with
     * a flash of the wrong theme.
     */
    $response->assertSee('nonce="'.$matches[1].'"', escape: false);
});

it('names every third party the browser is allowed to talk to', function (): void {
    enforcingCsp();

    $policy = (string) $this->get(route('home'))->headers->get('Content-Security-Policy');

    expect($policy)
        ->toContain('https://challenges.cloudflare.com')
        ->toContain('https://pay.lenco.co')
        ->toContain('https://pay.sandbox.lenco.co')
        ->toContain('https://maps.googleapis.com')
        ->toContain('https://fonts.bunny.net')
        /* Nothing may be embedded as an object or applet. */
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'");
});

it('does not put a policy on a JSON body, where it protects nothing', function (): void {
    enforcingCsp();

    expect($this->getJson(route('api.v1.platform'))->headers->has('Content-Security-Policy'))
        ->toBeFalse();
});

it('can be sent report-only while a deployment collects violations', function (): void {
    config()->set('security.csp.enabled', true);
    config()->set('security.csp.report_only', true);
    config()->set('security.csp.report_uri', 'https://reports.example/csp');

    $response = $this->get(route('home'));

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and((string) $response->headers->get('Content-Security-Policy-Report-Only'))
        ->toContain('report-uri https://reports.example/csp');
});

it('sends HSTS over HTTPS and never over plain HTTP', function (): void {
    /* An HSTS header on a plain-HTTP response is ignored by browsers anyway. */
    expect($this->get(route('home'))->headers->has('Strict-Transport-Security'))->toBeFalse();

    $secure = $this->get('https://localhost/');

    expect((string) $secure->headers->get('Strict-Transport-Security'))
        ->toContain('max-age=31536000')
        ->toContain('includeSubDomains')
        /*
         * Preload is off by default: submitting a domain to the preload list
         * is close to irreversible and belongs on the launch checklist, not
         * in a config default.
         */
        ->not->toContain('preload');
});

it('switches off the browser features the platform does not use', function (): void {
    $policy = (string) $this->get(route('home'))->headers->get('Permissions-Policy');

    expect($policy)
        ->toContain('camera=()')
        ->toContain('microphone=()')
        ->toContain('payment=()')
        /* Except this one: "Nearest first" asks the browser where the buyer is. */
        ->toContain('geolocation=(self)');
});

it('leaves the policy off in local development, where Vite needs inline everything', function (): void {
    config()->set('security.csp.enabled', false);

    expect($this->get(route('home'))->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('protects an authenticated page the same way', function (): void {
    enforcingCsp();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});
