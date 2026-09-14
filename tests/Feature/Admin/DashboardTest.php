<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ListingStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\OrderDispute;
use App\Modules\Payments\Enums\PayoutBatchStatus;
use App\Modules\Payments\Models\PayoutBatch;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use App\Support\Console\ConsoleCounters;
use App\Support\Console\ProvidesConsoleCounters;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
});

/**
 * The counter with the given key, or null.
 *
 * @param  array<int, array<string, mixed>>  $counters
 */
function counter(array $counters, string $key): ?array
{
    foreach ($counters as $entry) {
        if ($entry['key'] === $key) {
            return $entry;
        }
    }

    return null;
}

it('opens on what is waiting', function () {
    actingAsStaff([Role::PlatformAdmin]);

    Seller::factory()->count(2)->create(['verification_status' => VerificationStatus::Submitted]);
    Product::factory()->count(3)->create(['status' => ListingStatus::PendingReview, 'submitted_at' => now()]);

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $counters = $page->toArray()['props']['counters'];

            expect(counter($counters, 'sellers.verification')['value'])->toBe(2)
                ->and(counter($counters, 'listings.moderation')['value'])->toBe(3);

            return $page->component('admin/Dashboard');
        });
});

it('counts every queue the brief asks for', function () {
    actingAsStaff([Role::PlatformAdmin]);

    $page = $this->get(route('admin.dashboard'))->assertOk();

    $keys = array_column($page->viewData('page')['props']['counters'], 'key');

    expect($keys)->toContain(
        'sellers.verification',
        'listings.moderation',
        'mechanics.approval',
        'orders.disputes',
        'orders.unconfirmed',
        'inventory.stale_stock',
        'ratings.flagged',
        'reconciliation.exceptions',
        'payouts.awaiting_approval',
    );
});

it('keeps the money tiles off a moderator\'s dashboard', function () {
    actingAsStaff([Role::Moderator]);

    PayoutBatch::factory()->create(['status' => PayoutBatchStatus::AwaitingApproval]);

    $keys = array_column(
        $this->get(route('admin.dashboard'))->viewData('page')['props']['counters'],
        'key',
    );

    expect($keys)->toContain('listings.moderation')
        ->and($keys)->not->toContain('payouts.awaiting_approval')
        ->and($keys)->not->toContain('reconciliation.exceptions');
});

it('keeps the moderation tiles off a finance dashboard', function () {
    actingAsStaff([Role::Finance]);

    $keys = array_column(
        $this->get(route('admin.dashboard'))->viewData('page')['props']['counters'],
        'key',
    );

    expect($keys)->toContain('payouts.awaiting_approval')
        ->and($keys)->not->toContain('listings.moderation')
        ->and($keys)->not->toContain('orders.disputes');
});

it('links each counter at the queue that clears it', function () {
    actingAsStaff([Role::PlatformAdmin]);

    OrderDispute::factory()->create();

    $disputes = counter(
        $this->get(route('admin.dashboard'))->viewData('page')['props']['counters'],
        'orders.disputes',
    );

    expect($disputes['value'])->toBe(1)
        ->and($disputes['href'])->toBe(route('admin.disputes.index'))
        /* Money is held on both sides until it is decided. */
        ->and($disputes['tone'])->toBe('critical');
});

it('survives a module whose counter throws', function () {
    actingAsStaff([Role::PlatformAdmin]);

    app(ConsoleCounters::class)->register(BrokenCounters::class);

    $this->get(route('admin.dashboard'))->assertOk();
});

class BrokenCounters implements ProvidesConsoleCounters
{
    public function counters(): array
    {
        throw new RuntimeException('The catalogue is on fire.');
    }
}
