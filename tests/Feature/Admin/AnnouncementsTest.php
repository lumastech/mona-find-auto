<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AnnouncementAudience;
use App\Modules\Admin\Enums\AnnouncementLevel;
use App\Modules\Admin\Models\Announcement;
use App\Modules\Admin\Services\AnnouncementBoard;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    app(AnnouncementBoard::class)->flush();
});

it('shows a live banner to a guest on the storefront', function () {
    Announcement::factory()->create([
        'title' => 'Deliveries to Solwezi are suspended',
        'audience' => AnnouncementAudience::Everyone,
    ]);

    $this->get(route('home'))->assertInertia(
        fn ($page) => $page->where('announcements.0.title', 'Deliveries to Solwezi are suspended'),
    );
});

it('holds a scheduled banner back until it starts', function () {
    Announcement::factory()->upcoming()->create();

    $this->get(route('home'))->assertInertia(fn ($page) => $page->where('announcements', []));
});

it('takes a banner down when its window closes', function () {
    Announcement::factory()->finished()->create();

    $this->get(route('home'))->assertInertia(fn ($page) => $page->where('announcements', []));
});

it('keeps a seller banner out of a buyer\'s view', function () {
    Announcement::factory()->shownTo(AnnouncementAudience::Sellers)->create([
        'title' => 'Payouts run on Thursday this week',
    ]);

    $this->get(route('home'))->assertInertia(fn ($page) => $page->where('announcements', []));

    actingAsRole([Role::Seller]);

    $this->get(route('seller.dashboard'))->assertInertia(
        fn ($page) => $page->where('announcements.0.title', 'Payouts run on Thursday this week'),
    );
});

it('will not let a visitor dismiss a critical banner', function () {
    Announcement::factory()->create(['level' => AnnouncementLevel::Critical]);

    $this->get(route('home'))->assertInertia(
        fn ($page) => $page->where('announcements.0.dismissible', false),
    );
});

it('schedules a banner and shows it immediately', function () {
    actingAsStaff([Role::Moderator]);

    $this->post(route('admin.announcements.store'), [
        'title' => 'Card payments are slow',
        'body' => 'Lenco is investigating. Mobile money is unaffected.',
        'level' => AnnouncementLevel::Warning->value,
        'audience' => AnnouncementAudience::Everyone->value,
        'starts_at' => now()->subMinute()->toDateTimeString(),
    ])->assertRedirect();

    /* The cache is dropped on write: an urgent banner cannot wait a minute. */
    $this->get(route('home'))->assertInertia(
        fn ($page) => $page->where('announcements.0.title', 'Card payments are slow'),
    );
});

it('refuses a window that closes before it opens', function () {
    actingAsStaff([Role::Moderator]);

    $this->post(route('admin.announcements.store'), [
        'title' => 'Backwards',
        'body' => 'This would never be seen by anybody.',
        'level' => AnnouncementLevel::Info->value,
        'audience' => AnnouncementAudience::Everyone->value,
        'starts_at' => now()->addDay()->toDateTimeString(),
        'ends_at' => now()->toDateTimeString(),
    ])->assertSessionHasErrors('ends_at');

    expect(Announcement::query()->count())->toBe(0);
});

it('takes a banner down now without deleting what it said', function () {
    actingAsStaff([Role::Moderator]);

    $announcement = Announcement::factory()->create();

    $this->post(route('admin.announcements.deactivate', $announcement))->assertRedirect();

    expect($announcement->refresh()->is_active)->toBeFalse()
        ->and(Announcement::query()->count())->toBe(1);

    $this->get(route('home'))->assertInertia(fn ($page) => $page->where('announcements', []));
});

it('keeps finance out of the announcement screens', function () {
    actingAsStaff([Role::Finance]);

    $this->post(route('admin.announcements.store'), [
        'title' => 'Not finance work',
        'body' => 'A banner is a statement MonaFind makes to every visitor.',
        'level' => AnnouncementLevel::Info->value,
        'audience' => AnnouncementAudience::Everyone->value,
        'starts_at' => now()->toDateTimeString(),
    ])->assertForbidden();
});
