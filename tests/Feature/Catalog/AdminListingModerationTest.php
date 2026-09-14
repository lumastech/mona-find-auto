<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Catalog\Enums\InspectionStatus;
use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Models\Make;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\VehicleModel;
use App\Modules\Catalog\Services\CategoryTree;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');

    /*
     * Two-factor authentication is mandatory for staff, and EnsureStaffTwoFactor
     * holds an unenrolled account at /settings/security everywhere — so a staff
     * fixture that skips it never reaches the console at all.
     */
    $this->moderator = User::factory()->withTwoFactor()->withRole(Role::Moderator)->create();
});

/**
 * Act as the enrolled moderator these tests share.
 */
function asModerator(): User
{
    test()->actingAs(test()->moderator);

    return test()->moderator;
}

it('keeps sellers out of the moderation console', function (): void {
    $seller = Seller::factory()->create();

    $this->actingAs($seller->user)->get(route('admin.listings.index'))->assertForbidden();
});

it('shows a moderator the queue oldest first', function (): void {
    asModerator();

    $older = Product::factory()->pendingReview()->create(['submitted_at' => now()->subDays(3)]);
    $newer = Product::factory()->pendingReview()->create(['submitted_at' => now()->subDay()]);
    Product::factory()->create();

    $this->get(route('admin.listings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/listings/Index')
            ->has('listings.data', 2)
            ->where('listings.data.0.id', $older->id)
            ->where('listings.data.1.id', $newer->id)
            ->where('queueSize', 2));
});

it('publishes a listing from the queue', function (): void {
    $moderator = asModerator();
    $product = Product::factory()->pendingReview()->create();

    $this->post(route('admin.listings.publish', $product), ['note' => 'Photos are clear.'])
        ->assertRedirect(route('admin.listings.index'));

    expect($product->refresh()->status)->toBe(ListingStatus::Published)
        ->and($product->reviewed_by)->toBe($moderator->id);
});

it('returns per-field reasons to the seller on rejection', function (): void {
    asModerator();
    $product = Product::factory()->pendingReview()->create();

    $this->post(route('admin.listings.reject', $product), [
        'reason' => 'The photos do not show the part clearly enough.',
        'field_reasons' => [
            'photos' => 'Too dark to see the part.',
            /* Not a field a moderator may pin a reason to; it should be dropped. */
            'seller_id' => 'Nonsense.',
        ],
        'note' => 'Third attempt from this shop.',
    ])->assertRedirect();

    $product->refresh();

    expect($product->status)->toBe(ListingStatus::Rejected)
        ->and($product->rejection_fields)->toBe(['photos' => 'Too dark to see the part.']);
});

it('makes a moderator say what to fix rather than only that something is wrong', function (): void {
    asModerator();
    $product = Product::factory()->pendingReview()->create();

    $this->post(route('admin.listings.reject', $product), ['reason' => 'No.'])
        ->assertSessionHasErrors('reason');

    expect($product->refresh()->status)->toBe(ListingStatus::PendingReview);
});

it('keeps the internal note out of what the seller is told', function (): void {
    asModerator();
    $product = Product::factory()->pendingReview()->create();

    $this->post(route('admin.listings.reject', $product), [
        'reason' => 'The photos do not show the part clearly enough.',
        'note' => 'Third attempt from this shop.',
    ]);

    $product->refresh();

    expect($product->rejection_reason)->toBe('The photos do not show the part clearly enough.')
        ->and($product->reviewEvents()->first()->note)->toBe('Third attempt from this shop.')
        ->and($product->rejection_fields)->toBeNull();
});

it('sets the inspection badge and audits the reason', function (): void {
    $moderator = asModerator();
    $product = Product::factory()->create();

    $this->put(route('admin.listings.inspection', $product), [
        'inspection_status' => InspectionStatus::Inspected->value,
        'reason' => 'Checked the casting number against the OEM part.',
    ])->assertRedirect();

    expect($product->refresh()->inspection_status)->toBe(InspectionStatus::Inspected)
        ->and(AuditLog::query()
            ->where('action', 'listing.inspection.inspected')
            ->where('actor_id', $moderator->id)
            ->exists())->toBeTrue();
});

it('will not set the inspection badge without a reason', function (): void {
    asModerator();
    $product = Product::factory()->create();

    $this->put(route('admin.listings.inspection', $product), [
        'inspection_status' => InspectionStatus::Inspected->value,
    ])->assertSessionHasErrors('reason');

    expect($product->refresh()->inspection_status)->toBe(InspectionStatus::Uninspected);
});

it('refuses a finance staffer the moderation decisions', function (): void {
    $this->actingAs(User::factory()->withTwoFactor()->withRole(Role::Finance)->create());
    $product = Product::factory()->pendingReview()->create();

    $this->post(route('admin.listings.publish', $product))->assertForbidden();
    $this->put(route('admin.listings.inspection', $product), [
        'inspection_status' => InspectionStatus::Inspected->value,
        'reason' => 'Had a look.',
    ])->assertForbidden();
});

it('shows a moderator the buyer view beside the seller fields', function (): void {
    asModerator();
    $product = Product::factory()->pendingReview()->create();

    $this->get(route('admin.listings.show', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/listings/Show')
            ->has('listing.storefront.specification')
            ->has('breadcrumb')
            ->where('canModerate', true)
            ->where('canInspect', true));
});

it('lets a platform admin curate the reference lists', function (): void {
    $admin = User::factory()->withTwoFactor()->withRole(Role::PlatformAdmin)->create();

    $this->actingAs($admin)
        ->post(route('admin.reference.makes.store'), ['name' => 'Chery', 'country' => 'China'])
        ->assertRedirect();

    expect(Make::query()->where('name', 'Chery')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'catalog.make.created')->exists())->toBeTrue();
});

it('refuses two models with the same name under one make', function (): void {
    asModerator();
    $make = Make::factory()->named('Toyota')->create();
    VehicleModel::factory()->of($make)->create(['name' => 'Hilux', 'slug' => 'hilux']);

    $this->post(route('admin.reference.vehicle-models.store'), [
        'make_id' => $make->id,
        'name' => 'Hilux',
    ])->assertSessionHasErrors('slug');
});

it('lets the same model name exist under two different makes', function (): void {
    asModerator();
    $toyota = Make::factory()->named('Toyota')->create();
    $ford = Make::factory()->named('Ford')->create();

    VehicleModel::factory()->of($toyota)->create(['name' => 'Ranger', 'slug' => 'ranger']);

    $this->post(route('admin.reference.vehicle-models.store'), [
        'make_id' => $ford->id,
        'name' => 'Ranger',
    ])->assertSessionHasNoErrors();
});

it('refuses a category nested past the tree depth', function (): void {
    asModerator();
    $tree = app(CategoryTree::class);

    $engine = $tree->create(['name' => 'Engine']);
    $fuel = $tree->create(['name' => 'Fuel system'], $engine);
    $injectors = $tree->create(['name' => 'Fuel injectors'], $fuel);

    $this->post(route('admin.reference.categories.store'), [
        'name' => 'Nozzles',
        'parent_id' => $injectors->id,
    ])->assertSessionHasErrors('parent_id');
});
