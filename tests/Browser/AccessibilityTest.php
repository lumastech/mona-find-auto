<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Services\SellerPolicyService;
use App\Modules\Shopping\Services\CartService;
use App\Support\Roles\Role;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\ChecksAccessibility;

/*
 * WCAG 2.1 AA on the storefront, checked by axe-core in a real browser.
 *
 * The brief commits the storefront to AA, and the four pages here are the
 * ones a buyer cannot avoid: the home page they land on, the search results
 * they read, the listing they decide from, and the checkout they pay through.
 *
 * ## Why this is a browser test rather than a component test
 *
 * Contrast, focus order, landmark structure and accessible names are
 * properties of a rendered page, not of a component. A Vitest snapshot of a
 * button proves nothing about whether the button is reachable by keyboard in
 * the page it eventually lands in.
 *
 * Run with `php artisan dusk --filter=AccessibilityTest`.
 */

uses(DatabaseTruncation::class, ChecksAccessibility::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->buyer = User::factory()->create(['password' => bcrypt('password')]);
    $this->buyer->assignRole(Role::Buyer->value);

    $this->seller = Seller::factory()->create([
        'business_name' => 'Kabwata Motors',
        'verification_status' => VerificationStatus::Verified,
        'offers_pickup' => true,
        'offers_delivery' => false,
    ]);

    $policies = app(SellerPolicyService::class);

    foreach (PolicyType::cases() as $type) {
        $policies->publish($this->seller, $type, 'The '.$type->value.' policy of Kabwata Motors.');
    }

    $this->product = Product::factory()
        ->ofSeller($this->seller)
        ->create(['name' => 'Alternator']);

    $this->product->variants()->first()->forceFill(['price' => 120_000, 'quantity' => 5])->save();
});

test('the home page meets WCAG 2.1 AA', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/')->waitForText('MonaFind', 10);

        $this->assertAccessible($browser, 'The home page');
    });
});

test('search results meet WCAG 2.1 AA', function (): void {
    /*
     * The search page cannot render without Meilisearch, and that is by
     * design rather than an oversight: the ranking tiers ARE Meilisearch's
     * ranking rules, and the geo radius, facet distribution and matching
     * strategy have no Scout equivalent (see .ai/rules/search.md).
     *
     * Under the `collection` driver `ProductSearch::paginator()` throws, and
     * axe would then happily scan Laravel's error page and report its
     * contrast problems as the storefront's — which is worse than not running
     * at all, because it looks like a result.
     *
     * CI runs this job with a Meilisearch service. Locally, start one.
     */
    if (config('scout.driver') !== 'meilisearch') {
        test()->markTestSkipped(
            'The search page requires Meilisearch. Run with SCOUT_DRIVER=meilisearch and an index built.',
        );
    }

    $this->browse(function (Browser $browser): void {
        $browser->visit('/search?q=alternator')
            ->waitFor('[data-test="search-results"], [data-test="search-empty"]', 15);

        $this->assertAccessible($browser, 'Search results');
    });
});

test('a listing page meets WCAG 2.1 AA', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/listings/'.$this->product->slug)
            ->waitForText('Alternator', 10);

        $this->assertAccessible($browser, 'The listing page');
    });
});

test('a listing page meets WCAG 2.1 AA for a signed-in buyer', function (): void {
    /*
     * Worth checking separately: the contact block a guest sees blurred is
     * replaced with real values here, and a blurred-then-revealed element is
     * exactly the kind of thing that ends up with a contrast failure.
     */
    $this->browse(function (Browser $browser): void {
        $browser->loginAs($this->buyer)
            ->visit('/listings/'.$this->product->slug)
            ->waitForText('Alternator', 10);

        $this->assertAccessible($browser, 'The listing page, signed in');
    });
});

test('checkout meets WCAG 2.1 AA', function (): void {
    app(CartService::class)->add($this->buyer, $this->product->variants()->first()->fresh(), 1);

    $this->browse(function (Browser $browser): void {
        $browser->loginAs($this->buyer)
            ->visit('/checkout')
            ->waitForText('Checkout', 10);

        $this->assertAccessible($browser, 'Checkout');
    });
});

test('the terms modal on checkout meets WCAG 2.1 AA', function (): void {
    /*
     * A modal is the highest-risk thing on the storefront for accessibility:
     * it has to trap focus, be dismissible from the keyboard, and be
     * announced. It is also the one screen a buyer cannot skip — nothing can
     * be paid before the terms have been read.
     */
    app(CartService::class)->add($this->buyer, $this->product->variants()->first()->fresh(), 1);

    $this->browse(function (Browser $browser): void {
        $browser->loginAs($this->buyer)
            ->visit('/checkout')
            ->waitForText('Checkout', 10)
            ->click('@review-terms-'.$this->seller->getKey())
            ->waitForText('Ordering from Kabwata Motors');

        $this->assertAccessible($browser, 'The checkout terms modal');
    });
});

test('the storefront is navigable by keyboard alone', function (): void {
    /*
     * axe cannot see this one. It checks that elements have accessible names
     * and sufficient contrast; it does not press Tab. A buyer who cannot
     * reach the search box without a mouse has a page that passes axe and
     * fails them.
     */
    $this->browse(function (Browser $browser): void {
        $browser->visit('/')->waitForText('MonaFind', 10);

        $reachable = $browser->script(<<<'JS'
            const selector = 'a[href], button:not([disabled]), input:not([disabled]), '
                + 'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

            return Array.from(document.querySelectorAll(selector))
                .filter((el) => {
                    const style = window.getComputedStyle(el);
                    return style.display !== 'none'
                        && style.visibility !== 'hidden'
                        && el.getAttribute('aria-hidden') !== 'true';
                })
                .length;
        JS)[0];

        expect($reachable)->toBeGreaterThan(5);

        /*
         * A visible focus indicator. Removing the outline without replacing
         * it is the single most common way a site becomes unusable by
         * keyboard while looking perfectly fine.
         */
        $hasFocusStyle = $browser->script(<<<'JS'
            const link = document.querySelector('a[href]');
            if (!link) { return false; }

            link.focus();

            const style = window.getComputedStyle(link);

            return style.outlineStyle !== 'none'
                || parseFloat(style.outlineWidth) > 0
                || style.boxShadow !== 'none';
        JS)[0];

        expect($hasFocusStyle)->toBeTrue();
    });
});
