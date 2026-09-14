<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Admin\Database\Seeders\ContentPageSeeder;
use App\Modules\Admin\Enums\ContentPageStatus;
use App\Modules\Admin\Models\ContentPage;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Enums\FulfilmentMethod;
use App\Modules\Orders\Enums\PaymentMethod;
use App\Modules\Orders\Models\TermsAcceptance;
use App\Modules\Orders\Services\CheckoutService;
use App\Modules\Orders\Support\CheckoutSelection;
use App\Modules\Orders\Support\PlatformTerms;
use App\Modules\Sellers\Enums\PolicyType;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Services\SellerPolicyService;
use App\Modules\Shopping\Services\CartService;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->seed([SettingsSeeder::class, ContentPageSeeder::class]);
});

/**
 * Place one real order and hand back the terms acceptance it recorded.
 *
 * The acceptance is the whole point of versioning the terms page, so this
 * asserts against the row a buyer's checkout actually wrote rather than
 * against the setting the page bumped.
 */
function acceptanceFromACheckout(): TermsAcceptance
{
    $buyer = User::factory()->create();
    $policies = app(SellerPolicyService::class);
    $seller = Seller::factory()->create();

    foreach (PolicyType::cases() as $type) {
        $policies->publish($seller, $type, 'Version one of the '.$type->value.' policy.');
    }

    $variant = Product::factory()->ofSeller($seller)->create()->variants()->first();
    $variant->forceFill(['price' => 45_000, 'quantity' => 10])->save();

    app(CartService::class)->add($buyer, $variant->fresh(), 1);

    $checkout = app(CheckoutService::class);
    $selections = [];

    foreach ($checkout->view($buyer)->groups as $group) {
        $selections[$group->seller->getKey()] = new CheckoutSelection(
            sellerId: $group->seller->getKey(),
            method: FulfilmentMethod::Pickup,
            addressId: null,
            accepted: true,
            acceptedPolicies: array_map(
                static fn (array $policy): array => [
                    'policy_id' => $policy['policy_id'],
                    'version' => $policy['version'],
                ],
                $group->policyFingerprint(),
            ),
        );
    }

    $orderGroup = $checkout->place(
        $buyer,
        $selections,
        PaymentMethod::Card,
        Request::create('/checkout', 'POST'),
    );

    return TermsAcceptance::query()
        ->where('order_id', $orderGroup->orders->first()->getKey())
        ->sole();
}

it('seeds the five pages the brief names', function () {
    expect(ContentPage::query()->pluck('slug')->all())
        ->toEqualCanonicalizing(['about', 'faq', 'contact', 'platform-terms', 'privacy']);
});

it('serves a published page to a guest', function () {
    $this->get(route('pages.show', 'about'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('storefront/content/Show')
            ->where('page.slug', 'about'));
});

it('hides a draft page from the storefront', function () {
    $page = ContentPage::factory()->create(['status' => ContentPageStatus::Draft]);

    $this->get(route('pages.show', $page->slug))->assertNotFound();
});

it('writes a new version rather than overwriting the old one', function () {
    actingAsStaff([Role::Moderator]);

    $page = ContentPage::query()->where('slug', 'faq')->sole();
    $original = $page->currentVersion->body;

    $this->post(route('admin.content.publish', $page), [
        'title' => 'Frequently asked questions',
        'body' => str_repeat('The answer changed. ', 5),
        'change_note' => 'Added the question about stale stock.',
    ])->assertRedirect();

    $page->refresh();

    expect($page->versions()->count())->toBe(2)
        ->and($page->currentVersion->version)->toBe(2)
        /* Version one is still readable, word for word. */
        ->and($page->versions()->where('version', 1)->sole()->body)->toBe($original);
});

it('bumps the platform terms version, and the next checkout records it', function () {
    $before = acceptanceFromACheckout();

    expect($before->platform_terms_version)->toBe('1');

    actingAsStaff([Role::PlatformAdmin]);

    $terms = ContentPage::query()->where('slug', ContentPage::TERMS_SLUG)->sole();

    $this->post(route('admin.content.publish', $terms), [
        'title' => 'Platform terms of use',
        'body' => 'MonaFindAuto is a marketplace. Commission is charged to the seller, and VAT on goods is theirs.',
        'change_note' => 'Clarified who is responsible for VAT on the goods.',
    ])->assertRedirect();

    expect(settings('policies.platform_terms_version'))->toBe('2')
        ->and(PlatformTerms::current()->version)->toBe('2');

    /* The acceptance already written keeps pointing at what it was shown. */
    expect($before->refresh()->platform_terms_version)->toBe('1');

    $after = acceptanceFromACheckout();

    expect($after->platform_terms_version)->toBe('2');
});

it('copies the published words onto the terms buyers are shown', function () {
    actingAsStaff([Role::PlatformAdmin]);

    $terms = ContentPage::query()->where('slug', ContentPage::TERMS_SLUG)->sole();
    $body = 'MonaFind holds every payment in escrow unless the seller page says otherwise.';

    $this->post(route('admin.content.publish', $terms), [
        'title' => 'Platform terms of use',
        'body' => $body,
        'change_note' => 'Stated the escrow default plainly.',
    ]);

    expect(PlatformTerms::current()->body)->toBe($body);
});

it('keeps a moderator away from the platform terms', function () {
    actingAsStaff([Role::Moderator]);

    $terms = ContentPage::query()->where('slug', ContentPage::TERMS_SLUG)->sole();

    $this->post(route('admin.content.publish', $terms), [
        'title' => 'Platform terms of use',
        'body' => 'A moderator should not be able to rewrite what buyers agree to.',
        'change_note' => 'Trying it on.',
    ])->assertForbidden();

    expect(settings('policies.platform_terms_version'))->toBe('1');
});

it('lets a moderator publish an ordinary page', function () {
    actingAsStaff([Role::Moderator]);

    $page = ContentPage::query()->where('slug', 'contact')->sole();

    $this->post(route('admin.content.publish', $page), [
        'title' => 'Contact MonaFind',
        'body' => 'Reach us on support@monafindauto.zm during Zambian business hours.',
        'change_note' => 'New support address.',
    ])->assertRedirect();

    expect($page->refresh()->currentVersion->version)->toBe(2);
});

it('requires a note saying what changed', function () {
    actingAsStaff([Role::Moderator]);

    $page = ContentPage::query()->where('slug', 'faq')->sole();

    $this->post(route('admin.content.publish', $page), [
        'title' => 'Frequently asked questions',
        'body' => 'A body long enough to pass the minimum length rule.',
    ])->assertSessionHasErrors('change_note');

    expect($page->refresh()->versions()->count())->toBe(1);
});

it('saves a draft without taking the live page down', function () {
    actingAsStaff([Role::Moderator]);

    $page = ContentPage::query()->where('slug', 'faq')->sole();
    $live = $page->current_version_id;

    $this->post(route('admin.content.publish', $page), [
        'title' => 'Frequently asked questions',
        'body' => 'Still being written, and not ready for anybody to read.',
        'change_note' => 'Work in progress.',
        'publish' => false,
    ])->assertRedirect();

    $page->refresh();

    expect($page->versions()->count())->toBe(2)
        ->and($page->current_version_id)->toBe($live)
        ->and($page->status)->toBe(ContentPageStatus::Published);
});

it('keeps the seeded wording when the seeder runs again', function () {
    actingAsStaff([Role::Moderator]);

    $page = ContentPage::query()->where('slug', 'about')->sole();

    $this->post(route('admin.content.publish', $page), [
        'title' => 'About MonaFindAuto',
        'body' => 'Our own words, written by somebody who works here.',
        'change_note' => 'Rewritten for the Zambian launch.',
    ]);

    $this->seed(ContentPageSeeder::class);

    expect($page->refresh()->currentVersion->body)
        ->toBe('Our own words, written by somebody who works here.');
});
