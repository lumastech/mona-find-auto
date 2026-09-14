<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/**
 * Browser coverage lands with the checkout flow; this keeps the Dusk harness
 * exercised until then. Run with `php artisan dusk`.
 */
test('the storefront loads in a real browser', function () {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/')
            ->assertSee('Find the part')
            ->assertPresent('#storefront-search');
    });
});
