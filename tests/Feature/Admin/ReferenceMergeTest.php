<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Make;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\VehicleModel;
use App\Modules\Identity\Models\City;
use App\Modules\Identity\Models\Province;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Modules\Mechanics\Models\MechanicSpeciality;
use App\Modules\Sellers\Models\Seller;
use App\Support\Reference\CannotMergeReference;
use App\Support\Reference\ReferenceMerger;
use App\Support\Roles\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

it('lists every curated list, whoever owns it', function () {
    actingAsStaff([Role::PlatformAdmin]);

    $this->get(route('admin.reference-data.index'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $keys = array_column($page->toArray()['props']['lists'], 'key');

            expect($keys)->toEqualCanonicalizing([
                'makes', 'vehicle-models', 'categories',
                'specialities', 'provinces', 'cities',
            ]);

            return $page->component('admin/reference-data/Index');
        });
});

it('counts everything pointing at a row, so nobody retires a busy one', function () {
    actingAsStaff([Role::PlatformAdmin]);

    $make = Make::factory()->create(['name' => 'Toyota']);
    Product::factory()->count(3)->create(['make_id' => $make->id]);
    VehicleModel::factory()->count(2)->create(['make_id' => $make->id]);

    $rows = $this->get(route('admin.reference-data.index', ['list' => 'makes']))
        ->viewData('page')['props']['rows'];

    $toyota = collect($rows)->firstWhere('id', $make->id);

    /* Three listings plus two models: the links Catalog registered. */
    expect($toyota['usage'])->toBe(5);
});

it('moves every listing off the duplicate and deletes it', function () {
    actingAsStaff([Role::PlatformAdmin]);

    $keep = Make::factory()->create(['name' => 'Toyota']);
    $typo = Make::factory()->create(['name' => 'Toyata']);

    $product = Product::factory()->create(['make_id' => $typo->id]);
    $model = VehicleModel::factory()->create(['make_id' => $typo->id]);

    $this->post(route('admin.reference-data.merge', 'makes'), [
        'source_id' => $typo->id,
        'target_id' => $keep->id,
        'reason' => 'Somebody typed it wrong when adding a listing.',
    ])->assertRedirect();

    expect($product->refresh()->make_id)->toBe($keep->id)
        ->and($model->refresh()->make_id)->toBe($keep->id)
        ->and(Make::query()->find($typo->id))->toBeNull();
});

it('records what the merge actually moved', function () {
    $admin = actingAsStaff([Role::PlatformAdmin]);

    $keep = Make::factory()->create(['name' => 'Nissan']);
    $typo = Make::factory()->create(['name' => 'Nisan']);
    Product::factory()->count(2)->create(['make_id' => $typo->id]);

    $this->post(route('admin.reference-data.merge', 'makes'), [
        'source_id' => $typo->id,
        'target_id' => $keep->id,
        'reason' => 'Duplicate created by a bulk import.',
    ]);

    $entry = AuditLog::query()->where('action', 'reference.merged')->sole();

    expect($entry->actor_id)->toBe($admin->id)
        ->and($entry->before)->toBe(['id' => $typo->id, 'label' => 'Nisan'])
        ->and($entry->after['into'])->toBe('Nissan')
        ->and($entry->after['rows_moved'])->toBe(2)
        ->and($entry->after['moved'])->toBe(['products.make_id' => 2])
        ->and($entry->reason)->toBe('Duplicate created by a bulk import.');
});

it('moves towns across every module that points at one', function () {
    actingAsStaff([Role::PlatformAdmin]);

    $province = Province::factory()->create();
    $keep = City::factory()->for($province)->create(['name' => 'Kitwe']);
    $typo = City::factory()->for($province)->create(['name' => 'Kitwe Town']);

    $seller = Seller::factory()->create(['city_id' => $typo->id, 'province_id' => $province->id]);
    $mechanic = MechanicProfile::factory()->create(['city_id' => $typo->id, 'province_id' => $province->id]);

    $this->post(route('admin.reference-data.merge', 'cities'), [
        'source_id' => $typo->id,
        'target_id' => $keep->id,
        'reason' => 'The same town entered twice.',
    ])->assertRedirect();

    /* Sellers and Mechanics each declared their own column; both moved. */
    expect($seller->refresh()->city_id)->toBe($keep->id)
        ->and($mechanic->refresh()->city_id)->toBe($keep->id)
        ->and(City::query()->find($typo->id))->toBeNull();
});

it('does not give a mechanic the same speciality twice', function () {
    actingAsStaff([Role::PlatformAdmin]);

    $keep = MechanicSpeciality::factory()->create(['name' => 'Auto electrics']);
    $duplicate = MechanicSpeciality::factory()->create(['name' => 'Auto electricals']);

    /* This mechanic claims both halves of the duplicate. */
    $both = MechanicProfile::factory()->create();
    $both->specialities()->attach([$keep->id, $duplicate->id]);

    /* This one claims only the losing side, and must keep it. */
    $one = MechanicProfile::factory()->create();
    $one->specialities()->attach($duplicate->id);

    $this->post(route('admin.reference-data.merge', 'specialities'), [
        'source_id' => $duplicate->id,
        'target_id' => $keep->id,
        'reason' => 'The same trade, spelled two ways.',
    ])->assertRedirect();

    expect($both->specialities()->pluck('mechanic_specialities.id')->all())->toBe([$keep->id])
        ->and($one->specialities()->pluck('mechanic_specialities.id')->all())->toBe([$keep->id])
        ->and(DB::table('mechanic_profile_speciality')->count())->toBe(2);
});

it('re-parents the children of a merged category', function () {
    actingAsStaff([Role::PlatformAdmin]);

    $keep = Category::factory()->create(['name' => 'Brakes']);
    $duplicate = Category::factory()->create(['name' => 'Braking']);
    $child = Category::factory()->create(['name' => 'Brake pads', 'parent_id' => $duplicate->id]);

    $this->post(route('admin.reference-data.merge', 'categories'), [
        'source_id' => $duplicate->id,
        'target_id' => $keep->id,
        'reason' => 'One category, entered twice.',
    ])->assertRedirect();

    /* The child moved before the parent was deleted, so it survives. */
    expect($child->refresh()->parent_id)->toBe($keep->id)
        ->and(Category::query()->find($duplicate->id))->toBeNull();
});

it('refuses to merge a category into its own child', function () {
    $parent = Category::factory()->create(['name' => 'Engine']);
    $child = Category::factory()->create(['name' => 'Pistons', 'parent_id' => $parent->id]);

    expect(fn () => app(ReferenceMerger::class)->merge('categories', $parent, $child))
        ->toThrow(CannotMergeReference::class);
});

it('refuses to merge a row into itself', function () {
    actingAsStaff([Role::PlatformAdmin]);

    $make = Make::factory()->create();

    $this->post(route('admin.reference-data.merge', 'makes'), [
        'source_id' => $make->id,
        'target_id' => $make->id,
        'reason' => 'A mistake waiting to happen.',
    ])->assertSessionHasErrors('target_id');

    expect(Make::query()->find($make->id))->not->toBeNull();
});

it('leaves everything alone when a merge cannot complete', function () {
    actingAsStaff([Role::PlatformAdmin]);

    $keep = Make::factory()->create();
    $typo = Make::factory()->create();
    $product = Product::factory()->create(['make_id' => $typo->id]);

    $this->post(route('admin.reference-data.merge', 'makes'), [
        'source_id' => $typo->id,
        'target_id' => $keep->id,
        /* Too short: the reason is required and this never reaches the merger. */
        'reason' => 'no',
    ])->assertSessionHasErrors('reason');

    expect($product->refresh()->make_id)->toBe($typo->id)
        ->and(Make::query()->find($typo->id))->not->toBeNull();
});

it('renames and retires a row without touching what points at it', function () {
    actingAsStaff([Role::Moderator]);

    $make = Make::factory()->create(['name' => 'Toyata', 'is_active' => true]);
    $product = Product::factory()->create(['make_id' => $make->id]);

    $this->put(route('admin.reference-data.update', ['makes', $make->id]), [
        'name' => 'Toyota',
        'is_active' => false,
    ])->assertRedirect();

    expect($make->refresh()->name)->toBe('Toyota')
        ->and($make->is_active)->toBeFalse()
        ->and($product->refresh()->make_id)->toBe($make->id);
});

it('lets a moderator curate but not merge', function () {
    actingAsStaff([Role::Moderator]);

    $keep = Make::factory()->create();
    $typo = Make::factory()->create();

    $this->get(route('admin.reference-data.index'))->assertOk();
    $this->post(route('admin.reference-data.store', 'makes'), ['name' => 'Chery'])->assertRedirect();

    $this->post(route('admin.reference-data.merge', 'makes'), [
        'source_id' => $typo->id,
        'target_id' => $keep->id,
        'reason' => 'A moderator should not be able to do this.',
    ])->assertForbidden();

    expect(Make::query()->find($typo->id))->not->toBeNull();
});

it('keeps finance out of the reference lists entirely', function () {
    actingAsStaff([Role::Finance]);

    $this->post(route('admin.reference-data.store', 'makes'), ['name' => 'Chery'])
        ->assertForbidden();
});
