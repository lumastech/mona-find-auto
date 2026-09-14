<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Search\Models\SearchQuery;
use App\Modules\Search\Services\ProductSearch;
use App\Modules\Search\Services\SearchAnalytics;
use App\Modules\Search\Support\SearchCriteria;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    $this->analytics = app(SearchAnalytics::class);
});

it('writes down a search that found nothing', function (): void {
    usingMeilisearch();
    indexListings();

    $criteria = SearchCriteria::fromArray(['q' => 'Hilux Vigo bull bar']);
    $this->analytics->record($criteria, app(ProductSearch::class)->search($criteria));

    $logged = SearchQuery::query()->sole();

    expect($logged->term)->toBe('hilux vigo bull bar')
        ->and($logged->result_count)->toBe(0)
        ->and($logged->zero_results)->toBeTrue()
        ->and($logged->tier)->toBeNull();
});

it('logs the search the storefront page ran, filters and all', function (): void {
    usingMeilisearch();
    indexListings();

    $this->get(route('search', ['q' => 'alternator', 'inspected' => 1, 'verified' => 1]))->assertOk();

    $logged = SearchQuery::query()->sole();

    expect($logged->term)->toBe('alternator')
        ->and($logged->filters)->toBe(['inspected' => true, 'verified' => true])
        ->and($logged->zero_results)->toBeTrue();
});

/**
 * Paging is not searching. Counting page four as a fresh search would rank
 * "searches people scroll through" as though they were "searches people run",
 * which is the opposite of what the zero-result report is for.
 */
it('does not log a page of results as a new search', function (): void {
    usingMeilisearch();
    indexListings();

    $this->get(route('search', ['q' => 'alternator']))->assertOk();
    $this->get(route('search', ['q' => 'alternator', 'page' => 2]))->assertOk();

    expect(SearchQuery::query()->count())->toBe(1);
});

it('does not log an empty page load', function (): void {
    usingMeilisearch();
    indexListings();

    $this->get(route('search'))->assertOk();

    expect(SearchQuery::query()->count())->toBe(0);
});

it('ranks the terms that most often came back empty', function (): void {
    SearchQuery::factory()->count(3)->zeroResult('hilux bull bar')->create();
    SearchQuery::factory()->count(1)->zeroResult('corolla sump')->create();
    SearchQuery::factory()->count(9)->create(['term' => 'brake pads']);

    $report = $this->analytics->topZeroResultTerms();

    expect($report->pluck('term')->all())->toBe(['hilux bull bar', 'corolla sump'])
        ->and($report->first()['searches'])->toBe(3);
});

it('ignores searches older than the window', function (): void {
    SearchQuery::factory()->zeroResult('old term')->create(['created_at' => now()->subDays(90)]);
    SearchQuery::factory()->zeroResult('recent term')->create();

    expect($this->analytics->topZeroResultTerms(days: 30)->pluck('term')->all())->toBe(['recent term'])
        ->and($this->analytics->summary(30)['searches'])->toBe(1);
});

it('summarises how often search is failing buyers', function (): void {
    SearchQuery::factory()->count(3)->zeroResult()->create();
    SearchQuery::factory()->count(1)->create();

    expect($this->analytics->summary())->toMatchArray([
        'searches' => 4,
        'zero_results' => 3,
        'zero_result_rate' => 75.0,
    ]);
});

it('shows the zero-result report to staff and nobody else', function (): void {
    SearchQuery::factory()->count(2)->zeroResult('hilux bull bar')->create();

    $this->get(route('admin.search-insights'))->assertRedirect();

    $this->actingAs(User::factory()->withTwoFactor()->withRole(Role::Moderator)->create());

    $this->get(route('admin.search-insights'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/search/Insights')
            ->where('summary.zero_results', 2)
            ->where('zeroResultTerms.0.term', 'hilux bull bar')
            ->where('zeroResultTerms.0.searches', 2));
});

it('keeps a buyer out of the staff report', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Buyer)->create());

    $this->get(route('admin.search-insights'))->assertForbidden();
});
