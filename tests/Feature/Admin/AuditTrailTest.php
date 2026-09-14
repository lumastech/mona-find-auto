<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Catalog\Models\Make;
use App\Support\Database\ImmutableRecordException;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
});

/**
 * The entries a response is showing, in order.
 *
 * @return array<int, array<string, mixed>>
 */
function auditRows(TestResponse $response): array
{
    return $response->viewData('page')['props']['entries']['data'];
}

it('shows the trail to every staff role', function (Role $role) {
    actingAsStaff([$role]);

    audit(null, 'seller.verified', null, null, null, 'Papers checked.');

    $this->get(route('admin.audit.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/audit/Index'));
})->with([Role::Moderator, Role::Finance, Role::PlatformAdmin]);

it('filters by action', function () {
    actingAsStaff([Role::Moderator]);

    audit(null, 'listing.published', null, null, null, 'Looks genuine.');
    audit(null, 'seller.verified', null, null, null, 'Papers checked.');

    $rows = auditRows($this->get(route('admin.audit.index', ['action' => 'seller.verified'])));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['action'])->toBe('seller.verified');
});

it('filters by who did it', function () {
    $viewer = actingAsStaff([Role::Moderator]);
    $other = User::factory()->create();

    audit($viewer, 'listing.published');
    audit($other, 'listing.rejected');

    $rows = auditRows($this->get(route('admin.audit.index', ['actor_id' => $other->id])));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['action'])->toBe('listing.rejected');
});

it('filters by what was acted on', function () {
    actingAsStaff([Role::Moderator]);

    $make = Make::factory()->create();

    audit(null, 'reference.updated', $make);
    audit(null, 'listing.published');

    $rows = auditRows($this->get(route('admin.audit.index', [
        'subject_type' => $make->getMorphClass(),
        'subject_id' => $make->id,
    ])));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['subject_id'])->toBe($make->id);
});

it('filters by date, in whole Lusaka days', function () {
    actingAsStaff([Role::Moderator]);

    /* 23:30 in Lusaka on the 9th is 21:30 UTC — the same day either way. */
    $this->travelTo(now()->setDate(2026, 9, 9)->setTime(21, 30));
    audit(null, 'listing.published', null, null, null, 'Late on the ninth.');

    /* 01:00 in Lusaka on the 10th is 23:00 UTC on the NINTH. */
    $this->travelTo(now()->setDate(2026, 9, 9)->setTime(23, 0));
    audit(null, 'listing.rejected', null, null, null, 'Early on the tenth.');

    $this->travelBack();

    $rows = auditRows($this->get(route('admin.audit.index', [
        'from' => '2026-09-10',
        'to' => '2026-09-10',
    ])));

    /* A UTC-boundary filter would have missed this entirely. */
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['reason'])->toBe('Early on the tenth.');
});

it('searches the actor and the reason', function () {
    actingAsStaff([Role::Moderator]);

    audit(null, 'listing.rejected', null, null, null, 'Photographs too dark to see the part.');
    audit(null, 'listing.published', null, null, null, 'Clear pictures, genuine part number.');

    $rows = auditRows($this->get(route('admin.audit.index', ['search' => 'too dark'])));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['action'])->toBe('listing.rejected');
});

it('offers the distinct actions actually present', function () {
    actingAsStaff([Role::Moderator]);

    audit(null, 'listing.published');
    audit(null, 'listing.published');
    audit(null, 'seller.verified');

    $actions = $this->get(route('admin.audit.index'))->viewData('page')['props']['actions'];

    expect($actions)->toBe(['listing.published', 'seller.verified']);
});

it('exports the filtered rows as CSV', function () {
    actingAsStaff([Role::PlatformAdmin]);

    audit(null, 'listing.published', null, ['status' => 'pending_review'], ['status' => 'published'], 'Looks genuine.');
    audit(null, 'seller.verified', null, null, null, 'Papers checked.');

    $response = $this->get(route('admin.audit.export', ['action' => 'listing.published']));

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertDownload();

    $csv = $response->streamedContent();

    expect($csv)->toContain('id,recorded_at,actor')
        ->toContain('listing.published')
        ->toContain('Looks genuine.')
        /* before/after travel as JSON: every action has a different shape. */
        ->toContain('{""status"":""published""}')
        ->not->toContain('seller.verified');
});

it('records the export itself', function () {
    $admin = actingAsStaff([Role::PlatformAdmin]);

    $this->get(route('admin.audit.export'))->streamedContent();

    $entry = AuditLog::query()->where('action', 'audit.exported')->sole();

    expect($entry->actor_id)->toBe($admin->id);
});

it('refuses the export to everybody but a platform administrator', function (Role $role) {
    actingAsStaff([$role]);

    $this->get(route('admin.audit.export'))->assertForbidden();
})->with([Role::Moderator, Role::Finance]);

it('keeps the trail out of a seller\'s reach', function () {
    actingAsRole([Role::Seller]);

    $this->get(route('admin.audit.index'))->assertForbidden();
});

it('cannot be edited or deleted, whatever the viewer does', function () {
    $entry = audit(null, 'listing.published', null, null, null, 'Looks genuine.');

    expect(fn () => $entry->update(['reason' => 'Something else.']))
        ->toThrow(ImmutableRecordException::class)
        ->and(fn () => $entry->delete())
        ->toThrow(ImmutableRecordException::class);
});
