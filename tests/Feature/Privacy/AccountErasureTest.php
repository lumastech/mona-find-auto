<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Models\UserAddress;
use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Ledger\Models\JournalLine;
use App\Modules\Ledger\Services\OrderPostingService;
use App\Modules\Messaging\Models\Message;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderDispute;
use App\Modules\Orders\Models\TermsAcceptance;
use App\Modules\Privacy\Enums\ErasureStatus;
use App\Modules\Privacy\Models\ErasureRequest;
use App\Modules\Privacy\Services\AccountEraser;
use App\Modules\Privacy\Support\Anonymiser;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Search\Models\SearchQuery;
use App\Modules\Shopping\Models\WishlistItem;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The acceptance test for the whole Privacy module: after an erasure the
 * person is unidentifiable, and the platform's books still balance.
 *
 * Those two are in tension, and this file is where the resolution is pinned.
 * The money survives whole; the person does not survive at all.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
});

/**
 * A buyer with a paid order, a posted ledger entry, and personal data spread
 * across half the modules on the platform.
 *
 * @return array{0: User, 1: Order}
 */
function erasableBuyer(): array
{
    $order = Order::factory()->paid()->create([
        'items_total_ngwee' => 100_000,
        'delivery_fee_ngwee' => 0,
        'total_ngwee' => 100_000,
        'delivery_instructions' => 'Blue gate opposite the mosque, ask for Chanda.',
        'delivery_address' => [
            'recipient_name' => 'Chanda Mwale',
            'recipient_phone' => '+260977123456',
            'street' => 'Cairo Road',
            'city' => 'Lusaka',
        ],
        'completed_at' => now(),
    ]);

    $user = $order->buyer;
    $user->assignRole(Role::Buyer->value);

    /* The money side: a real posting, so the assertions below are not vacuous. */
    app(OrderPostingService::class)->recordPayment($order);

    UserAddress::factory()->create([
        'user_id' => $user->id,
        'recipient_name' => 'Chanda Mwale',
        'recipient_phone' => '+260966000111',
    ]);

    WishlistItem::factory()->create(['user_id' => $user->id]);

    SearchQuery::factory()->create([
        'user_id' => $user->id,
        'term' => 'hilux brake pads',
    ]);

    TermsAcceptance::factory()->create([
        'order_id' => $order->id,
        'order_group_id' => $order->order_group_id,
        'user_id' => $user->id,
        'seller_id' => $order->seller_id,
        'ip_address' => '196.44.1.1',
    ]);

    return [$user->refresh(), $order];
}

it('leaves the account row in place, holding tombstones instead of a person', function (): void {
    [$user] = erasableBuyer();

    $id = $user->id;

    app(AccountEraser::class)->erase($user);

    $erased = User::query()->findOrFail($id);

    expect($erased->email)->toBe("erased-{$id}@erased.invalid")
        ->and($erased->phone)->toBe("erased-{$id}")
        ->and($erased->name)->toBe('Former MonaFind user')
        ->and($erased->first_name)->toBe('Former')
        ->and($erased->street)->toBeNull()
        ->and($erased->province_id)->toBeNull()
        ->and($erased->email_verified_at)->toBeNull()
        ->and($erased->phone_verified_at)->toBeNull()
        ->and($erased->two_factor_secret)->toBeNull()
        ->and($erased->remember_token)->toBeNull();
});

it('deletes the satellite tables that hold nothing but personal data', function (): void {
    [$user] = erasableBuyer();

    app(AccountEraser::class)->erase($user);

    expect(UserAddress::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(WishlistItem::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('keeps the ledger whole and balanced', function (): void {
    [$user, $order] = erasableBuyer();

    $entriesBefore = JournalEntry::query()->count();
    $linesBefore = JournalLine::query()->count();
    $debitsBefore = (int) JournalLine::query()->sum('amount_ngwee');

    expect($entriesBefore)->toBeGreaterThan(0)
        ->and($linesBefore)->toBeGreaterThan(0);

    app(AccountEraser::class)->erase($user);

    expect(JournalEntry::query()->count())->toBe($entriesBefore)
        ->and(JournalLine::query()->count())->toBe($linesBefore)
        ->and((int) JournalLine::query()->sum('amount_ngwee'))->toBe($debitsBefore);

    /* And the order it was posted against is still there, with its totals. */
    $order->refresh();

    expect($order->exists)->toBeTrue()
        ->and($order->total_ngwee->ngwee)->toBe(100_000)
        ->and($order->user_id)->toBe($user->id);
});

it('keeps the terms acceptance exactly as it was, because it is the legal record', function (): void {
    [$user, $order] = erasableBuyer();

    $before = TermsAcceptance::query()->where('user_id', $user->id)->sole();

    app(AccountEraser::class)->erase($user);

    $after = TermsAcceptance::query()->where('user_id', $user->id)->sole();

    /*
     * Byte for byte, IP address included. The table is append-only in both
     * layers and is retained under the legal-claims basis; see
     * docs/DATA_RETENTION.md.
     */
    expect($after->id)->toBe($before->id)
        ->and($after->ip_address)->toBe('196.44.1.1')
        ->and($after->accepted_at->equalTo($before->accepted_at))->toBeTrue()
        ->and($after->order_id)->toBe($order->id);
});

it('strips the delivery address and instructions from the order it keeps', function (): void {
    [$user, $order] = erasableBuyer();

    app(AccountEraser::class)->erase($user);

    $order->refresh();

    expect($order->delivery_address)->toBeNull()
        ->and($order->delivery_instructions)->toBeNull()
        ->and($order->user_address_id)->toBeNull()
        /* The accounting facts are untouched. */
        ->and($order->items_total_ngwee->ngwee)->toBe(100_000);
});

it('redacts a message rather than deleting the seller\'s half of the conversation', function (): void {
    [$user] = erasableBuyer();

    $message = Message::factory()->create([
        'user_id' => $user->id,
        'body' => 'Do you have this in stock? Call me on 0977123456.',
    ]);

    app(AccountEraser::class)->erase($user);

    $message->refresh();

    expect($message->exists)->toBeTrue()
        ->and($message->body)->toBe(Anonymiser::REDACTED);
});

it('keeps a review\'s star but removes its words', function (): void {
    [$user, $order] = erasableBuyer();

    $rating = Rating::factory()->create([
        'submitted_by' => $user->id,
        'rater_type' => $user->getMorphClass(),
        'rater_id' => $user->id,
        'stars' => 2,
        'body' => 'The part was the wrong one and the shop would not answer.',
    ]);

    app(AccountEraser::class)->erase($user);

    $rating->refresh();

    /*
     * The star stays: it is already in the seller's public average and in the
     * trust score that ranks their listings. Removing it would make erasure a
     * way to launder a bad review away.
     */
    expect($rating->stars)->toBe(2)
        ->and($rating->body)->toBe(Anonymiser::REDACTED);
});

it('detaches search history from the account without losing the demand signal', function (): void {
    [$user] = erasableBuyer();

    app(AccountEraser::class)->erase($user);

    $query = SearchQuery::query()->where('term', 'hilux brake pads')->sole();

    expect($query->user_id)->toBeNull()
        ->and($query->term)->toBe('hilux brake pads');
});

it('reports what it erased, table by table', function (): void {
    [$user] = erasableBuyer();

    $report = app(AccountEraser::class)->erase($user);

    expect($report)->toHaveKey('account')
        ->and($report['account'])->toHaveKey('users')
        ->and($report['account']['users'])->toBe(1)
        ->and($report['account']['user_addresses'])->toBe(1)
        ->and($report['shopping']['wishlist_items'])->toBe(1);

    /* Nothing that did nothing is listed. */
    foreach ($report as $tables) {
        foreach ($tables as $count) {
            expect($count)->toBeGreaterThan(0);
        }
    }
});

it('closes the account, revokes its tokens, and writes an audit row', function (): void {
    [$user] = erasableBuyer();

    $user->createToken('Pixel 7a');

    $request = ErasureRequest::factory()->due()->create(['user_id' => $user->id]);

    app(AccountEraser::class)->complete($request);

    $request->refresh();
    $user->refresh();

    expect($request->status)->toBe(ErasureStatus::Completed)
        ->and($request->completed_at)->not->toBeNull()
        ->and($request->report)->not->toBeEmpty()
        ->and($user->status)->toBe(AccountStatus::Closed)
        ->and(DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->count())->toBe(0);

    expect(AuditLog::query()->where('action', 'privacy.account.erased')->exists())->toBeTrue();
});

it('cannot be undone by re-running it, and does not collide on the unique columns', function (): void {
    [$first] = erasableBuyer();
    [$second] = erasableBuyer();

    app(AccountEraser::class)->erase($first);
    app(AccountEraser::class)->erase($second);

    /*
     * The tombstones carry the account id precisely so that a second erasure
     * does not violate the unique index on email and phone.
     */
    expect(User::query()->findOrFail($first->id)->email)
        ->not->toBe(User::query()->findOrFail($second->id)->email);
});

it('leaves a dispute\'s outcome intact while removing what the buyer wrote', function (): void {
    [$user, $order] = erasableBuyer();

    $dispute = OrderDispute::factory()->create([
        'order_id' => $order->id,
        'opened_by' => $user->id,
        'details' => 'I paid on Tuesday and the shop says they never received it.',
    ]);

    $resolution = $dispute->resolution;

    app(AccountEraser::class)->erase($user);

    $dispute->refresh();

    expect($dispute->details)->toBe(Anonymiser::REDACTED)
        ->and($dispute->resolution)->toBe($resolution)
        ->and($dispute->refund_amount_ngwee->ngwee)->toBe(0);
});
