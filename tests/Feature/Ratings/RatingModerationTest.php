<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Ratings\Enums\RatingDirection;
use App\Modules\Ratings\Enums\RatingStatus;
use App\Modules\Ratings\Enums\ReportReason;
use App\Modules\Ratings\Enums\ReportStatus;
use App\Modules\Ratings\Events\RatingModerated;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Ratings\Models\RatingReport;
use App\Modules\Ratings\Services\RatingModerationService;
use App\Modules\Ratings\Services\RatingService;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role as SpatieRole;

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    Notification::fake();

    $this->moderation = app(RatingModerationService::class);

    SpatieRole::findOrCreate(Role::Moderator->value, 'web');
    $this->moderator = User::factory()->withTwoFactor()->create();
    $this->moderator->assignRole(Role::Moderator->value);

    $this->buyer = User::factory()->create();
    $this->seller = Seller::factory()->create();

    $order = Order::factory()->completed()->create([
        'user_id' => $this->buyer->getKey(),
        'seller_id' => $this->seller->getKey(),
    ]);

    $this->review = app(RatingService::class)->submit(
        $order,
        RatingDirection::BuyerToSeller,
        $this->buyer,
        1,
        'Sold me the wrong part and would not swap it.',
    );

    $this->reporter = User::factory()->create();
});

it('leaves a reported review on the page until somebody reads it', function (): void {
    $this->moderation->report($this->review, $this->reporter, ReportReason::Untrue, 'This never happened.');

    expect($this->review->refresh()->status)->toBe(RatingStatus::Published)
        ->and($this->review->reports_count)->toBe(1);
});

it('takes an abusive review off the page on the first report', function (): void {
    $this->moderation->report($this->review, $this->reporter, ReportReason::Abusive, 'Full of insults.');

    expect($this->review->refresh()->status)->toBe(RatingStatus::PendingReview);
});

it('holds a review back once enough people have objected', function (): void {
    settings()->set('ratings.moderation.reports_before_review', 2);

    $this->moderation->report($this->review, $this->reporter, ReportReason::Untrue);
    expect($this->review->refresh()->status)->toBe(RatingStatus::Published);

    $this->moderation->report($this->review, User::factory()->create(), ReportReason::Untrue);
    expect($this->review->refresh()->status)->toBe(RatingStatus::PendingReview);
});

it('counts one report per person however many times they press it', function (): void {
    $this->moderation->report($this->review, $this->reporter, ReportReason::Untrue);
    $this->moderation->report($this->review, $this->reporter, ReportReason::Spam);

    expect(RatingReport::query()->count())->toBe(1)
        ->and($this->review->refresh()->reports_count)->toBe(1);
});

it('hides a review with a reason, audits it, and upholds the reports', function (): void {
    $this->moderation->report($this->review, $this->reporter, ReportReason::Untrue);

    $this->moderation->hide($this->review, $this->moderator, 'Refers to a different shop entirely.');

    $this->review->refresh();

    expect($this->review->status)->toBe(RatingStatus::Hidden)
        ->and($this->review->moderation_reason)->toContain('different shop')
        ->and($this->review->moderated_by)->toBe($this->moderator->getKey())
        ->and($this->review->reports()->first()->status)->toBe(ReportStatus::Upheld);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'rating.hidden',
        'subject_id' => $this->review->getKey(),
    ]);
});

it('restores a review and dismisses the reports against it', function (): void {
    $this->moderation->report($this->review, $this->reporter, ReportReason::Abusive);
    $this->moderation->restore($this->review->refresh(), $this->moderator, 'Blunt, but within the rules.');

    $this->review->refresh();

    expect($this->review->status)->toBe(RatingStatus::Published)
        ->and($this->review->published_at)->not->toBeNull()
        ->and($this->review->reports()->first()->status)->toBe(ReportStatus::Dismissed);
});

it('drops a hidden review out of the aggregate and puts it back on restore', function (): void {
    $ratings = app(RatingService::class);

    expect($ratings->aggregateFor($this->seller)->count)->toBe(1);

    $this->moderation->hide($this->review, $this->moderator, 'Not about this shop.');
    expect($ratings->aggregateFor($this->seller)->count)->toBe(0);

    $this->moderation->restore($this->review->refresh(), $this->moderator, 'Checked again, it is genuine.');
    expect($ratings->aggregateFor($this->seller)->count)->toBe(1);
});

it('announces only the moves that change what a seller is scored on', function (): void {
    Event::fake([RatingModerated::class]);

    $this->moderation->hide($this->review, $this->moderator, 'Off topic entirely.');

    Event::assertDispatched(
        RatingModerated::class,
        fn (RatingModerated $event): bool => $event->changesAggregate(),
    );
});

it('keeps a hidden review off the storefront', function (): void {
    $this->moderation->hide($this->review, $this->moderator, 'Names a member of staff.');

    $this->getJson(route('api.v1.ratings.index', ['seller' => $this->seller->slug]))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('shows the queue to a moderator and nobody else', function (): void {
    $this->moderation->report($this->review, $this->reporter, ReportReason::Abusive, 'Insulting.');

    $this->actingAs($this->moderator)
        ->get(route('admin.ratings.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/ratings/Index')
            ->has('ratings.data', 1)
        );

    $this->actingAs($this->buyer)
        ->get(route('admin.ratings.index'))
        ->assertForbidden();
});

it('insists on a reason before hiding anything', function (): void {
    $this->actingAs($this->moderator)
        ->post(route('admin.ratings.hide', $this->review), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($this->review->refresh()->status)->toBe(RatingStatus::Published);
});

it('hides a review through the console', function (): void {
    $this->actingAs($this->moderator)
        ->post(route('admin.ratings.hide', $this->review), [
            'reason' => 'The review is about a different order.',
        ])
        ->assertRedirect();

    expect($this->review->refresh()->status)->toBe(RatingStatus::Hidden);
});

it('lets a buyer report a review but not their own', function (): void {
    $this->actingAs($this->reporter)
        ->post(route('ratings.report', $this->review), ['reason' => ReportReason::Untrue->value])
        ->assertRedirect();

    expect(RatingReport::query()->count())->toBe(1);

    $this->actingAs($this->buyer)
        ->post(route('ratings.report', $this->review), ['reason' => ReportReason::Untrue->value])
        ->assertForbidden();

    expect(RatingReport::query()->count())->toBe(1);
});

it('shows the review queue what the screen redacted', function (): void {
    $order = Order::factory()->completed()->create(['seller_id' => $this->seller->getKey()]);

    $rating = app(RatingService::class)->submit(
        $order,
        RatingDirection::BuyerToSeller,
        $order->buyer,
        4,
        'Good. Call 0977123456 for the same one.',
    );

    expect($rating->wasRedacted())->toBeTrue();

    $this->actingAs($this->moderator)
        ->get(route('admin.ratings.index', ['status' => RatingStatus::Published->value]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ratings.data.1.was_redacted', true)
        );

    expect(Rating::query()->count())->toBe(2);
});
