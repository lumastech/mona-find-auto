<?php

use App\Contracts\SmsProvider;
use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\City;
use App\Modules\Ledger\Database\Seeders\LedgerAccountSeeder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderGroup;
use App\Modules\Search\Services\ListingIndexer;
use App\Modules\Search\Support\ProductIndex;
use App\Modules\Search\Support\SearchResults;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use App\Support\Money\Money;
use App\Support\Roles\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Scout\EngineManager;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Contracts\TasksQuery;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\DuskTestCase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

/*
 * Roles and the chart of accounts are platform infrastructure rather than
 * fixtures — a deployment always has both, and nothing can be posted without
 * the chart — so every feature test starts with them in place.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => test()->seed([RoleSeeder::class, LedgerAccountSeeder::class]))
    ->in('Feature');

/*
 * Browser tests drive a real Chrome through Laravel Dusk. They are excluded
 * from `php artisan test` (see phpunit.xml) and run with `php artisan dusk`.
 */
pest()->extend(DuskTestCase::class)->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeMoney', function (int $ngwee) {
    return $this->toBeInstanceOf(Money::class)
        ->and($this->value->ngwee)->toBe($ngwee);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Sign in as a user holding the given platform roles.
 *
 * @param  array<int, Role>  $roles
 */
function actingAsRole(array $roles, array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    foreach ($roles as $role) {
        SpatieRole::findOrCreate($role->value, 'web');
    }

    $user->assignRole(array_map(static fn (Role $role): string => $role->value, $roles));

    test()->actingAs($user);

    return $user;
}

/**
 * Sign in as a member of MonaFind staff, already enrolled in 2FA.
 *
 * Staff accounts are held at the enrolment screen until they have confirmed
 * two-factor authentication (EnsureStaffTwoFactor), so a console test that
 * used actingAsRole() directly would get a redirect rather than the screen it
 * was asserting about — and the failure looks like a permissions bug.
 *
 * @param  array<int, Role>  $roles
 * @param  array<string, mixed>  $attributes
 */
function actingAsStaff(array $roles, array $attributes = []): User
{
    return actingAsRole($roles, [
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
        'two_factor_confirmed_at' => now(),
        ...$attributes,
    ]);
}

/**
 * A complete, valid registration payload.
 *
 * Registration touches a seeded reference list, so this creates the city it
 * points at. Override any field to exercise a validation branch.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registrationPayload(array $overrides = []): array
{
    $city = City::query()->first() ?? City::factory()->create();

    return [
        'first_name' => 'Chanda',
        'last_name' => 'Mwale',
        'email' => 'chanda@example.test',
        'phone' => '0977123456',
        'province_id' => $city->province_id,
        'city_id' => $city->id,
        'street' => 'Cairo Road',
        'plot_number' => '42',
        'password' => 'password',
        'password_confirmation' => 'password',
        /*
         * The two agreements registration cannot proceed without, and the one
         * it can. Part of the default payload because they are part of what
         * registering means — a test about phone-number formats should not
         * have to know about the Data Protection Act to stay green.
         *
         * Pass 'accept_terms' => false to exercise the refusal path.
         */
        'accept_terms' => true,
        'accept_privacy' => true,
        'accept_marketing' => false,
        ...$overrides,
    ];
}

/**
 * An uploaded video file that survives a real mime-type check.
 *
 * UploadedFile::fake()->create() writes an empty file, and media library
 * reads the mime type off the bytes rather than off the name — so a listing
 * video fixture has to carry a real MP4 header.
 */
function fakeVideoUpload(string $name = 'walkround.mp4', int $kilobytes = 64): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'mfa-test-video-').'.mp4';

    /* An ISO base media "ftyp" box: enough for finfo to call this video/mp4. */
    file_put_contents(
        $path,
        "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", $kilobytes * 1024),
    );

    return new UploadedFile($path, $name, 'video/mp4', null, true);
}

/**
 * The plaintext code the fake SMS provider last sent to a number.
 *
 * Codes are stored hashed, so a test that needs to type one back in has to
 * read it out of the message that was sent.
 */
function codeSentTo(string $phone): string
{
    $messages = app(SmsProvider::class)->messagesTo($phone);

    expect($messages)->not->toBeEmpty();

    preg_match('/\b(\d{6})\b/', end($messages)['message'], $matches);

    return $matches[1] ?? '';
}

/**
 * Point this test at a real Meilisearch, or skip it.
 *
 * The ranking the storefront depends on is Meilisearch's ranking — tiers,
 * typo tolerance, geo filters and custom ranking rules are the engine's
 * behaviour, not ours. Asserting them against Scout's collection driver would
 * be asserting that a stub does what we told the stub to do, so these tests
 * talk to the real thing (compose.yaml provides one) and skip when there is
 * none rather than passing vacuously.
 *
 * The index is dropped and rebuilt from ProductIndex's settings each time, so
 * a test never inherits documents or a ranking configuration from the last
 * one.
 */
function usingMeilisearch(): void
{
    $host = (string) config('scout.meilisearch.host');
    $key = config('scout.meilisearch.key');

    $client = new MeilisearchClient($host, is_string($key) ? $key : null);

    try {
        $client->health();
    } catch (Throwable $exception) {
        test()->markTestSkipped('Meilisearch is not reachable at '.$host.' ('.$exception->getMessage().').');
    }

    config()->set('scout.driver', 'meilisearch');
    config()->set('scout.prefix', 'mfa_testing_');
    /* Indexing has to have happened by the time the assertion runs. */
    config()->set('scout.queue', false);

    app()->instance(MeilisearchClient::class, $client);
    app(EngineManager::class)->forgetDrivers();

    $index = config('scout.prefix').ProductIndex::NAME;

    try {
        $client->waitForTask($client->deleteIndex($index)['taskUid']);
    } catch (Throwable) {
        /* There was no index to delete. */
    }

    $client->waitForTask($client->createIndex($index, ['primaryKey' => 'id'])['taskUid']);
    $client->waitForTask(
        $client->index($index)->updateSettings(ProductIndex::settings())['taskUid'],
    );
}

/**
 * Push everything currently visible into the index and wait for Meilisearch
 * to finish thinking about it.
 *
 * Meilisearch accepts documents asynchronously, so a search issued straight
 * after an import is a race the test loses roughly half the time.
 */
function indexListings(): void
{
    app(ListingIndexer::class)->rebuild();

    meilisearchSettled();
}

/**
 * Block until no task is queued or running.
 */
function meilisearchSettled(int $timeoutMs = 10000): void
{
    $client = app(MeilisearchClient::class);
    $deadline = microtime(true) + $timeoutMs / 1000;

    do {
        $pending = $client->getTasks((new TasksQuery)->setStatuses(['enqueued', 'processing']))->getResults();

        if ($pending === []) {
            return;
        }

        usleep(50_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('Meilisearch did not finish indexing within '.$timeoutMs.'ms.');
}

/**
 * The listing names a search returned, in the order they came back.
 *
 * @return array<int, string>
 */
function resultNames(SearchResults $results): array
{
    return array_map(
        static fn (Product $product): string => $product->name,
        $results->listings->items(),
    );
}

/**
 * A placed, unpaid order group with one order inside it, totals aligned.
 *
 * Payments tests all start here: a group that can be paid. The totals are
 * pushed onto the group explicitly because the order factory builds the order
 * and its group independently, and a payment reads the GROUP's total — a
 * mismatch between the two would make every amount assertion meaningless.
 */
function payableGroup(int $total = 100_000): OrderGroup
{
    $order = Order::factory()->create([
        'items_total_ngwee' => $total,
        'delivery_fee_ngwee' => 0,
        'total_ngwee' => $total,
    ]);

    $group = $order->group;

    $group->forceFill([
        'items_total_ngwee' => $total,
        'delivery_total_ngwee' => 0,
        'total_ngwee' => $total,
    ])->save();

    return $group->refresh();
}

/**
 * A seller with $completed completed orders and $disputed of them disputed.
 */
function sellerWithHistory(int $completed, int $disputed = 0, array $attributes = []): Seller
{
    $seller = Seller::factory()->create([
        'verification_status' => VerificationStatus::Verified,
        'verified_at' => now()->subDays(120),
        ...$attributes,
    ]);

    for ($i = 0; $i < $completed; $i++) {
        Order::factory()->for($seller)->create([
            'status' => OrderStatus::Completed,
            'paid_at' => now()->subDays(5),
            'completed_at' => now()->subDays(2),
            'disputed_at' => $i < $disputed ? now()->subDays(3) : null,
        ]);
    }

    return $seller->refresh();
}
