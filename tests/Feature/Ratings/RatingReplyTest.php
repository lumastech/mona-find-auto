<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Ratings\Enums\RatingDirection;
use App\Modules\Ratings\Exceptions\ReplyNotAllowed;
use App\Modules\Ratings\Models\Rating;
use App\Modules\Ratings\Services\RatingService;
use App\Modules\Sellers\Models\Seller;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as SpatieRole;

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    Notification::fake();

    $this->ratings = app(RatingService::class);

    SpatieRole::findOrCreate(Role::Seller->value, 'web');

    $this->buyer = User::factory()->create();
    $this->sellerUser = User::factory()->withTwoFactor()->create();
    $this->sellerUser->assignRole(Role::Seller->value);
    $this->seller = Seller::factory()->for($this->sellerUser)->create();

    $order = Order::factory()->completed()->create([
        'user_id' => $this->buyer->getKey(),
        'seller_id' => $this->seller->getKey(),
    ]);

    $this->review = $this->ratings->submit(
        $order,
        RatingDirection::BuyerToSeller,
        $this->buyer,
        2,
        'The housing was cracked when I opened the box.',
    );
});

it('lets the seller reply once', function (): void {
    $this->ratings->reply($this->review, $this->sellerUser, $this->seller, 'Sorry about that — replaced the same day.');

    expect($this->review->refresh()->hasReply())->toBeTrue()
        ->and($this->review->reply_body)->toContain('replaced the same day')
        ->and($this->review->replied_by)->toBe($this->sellerUser->getKey());
});

it('refuses a second reply', function (): void {
    $this->ratings->reply($this->review, $this->sellerUser, $this->seller, 'Sorry about that — replaced it.');

    expect(fn () => $this->ratings->reply($this->review->refresh(), $this->sellerUser, $this->seller, 'Actually, it was fine.'))
        ->toThrow(ReplyNotAllowed::class);

    expect($this->review->refresh()->reply_body)->toContain('replaced it');
});

it('refuses a reply from a shop the review is not about', function (): void {
    $otherSeller = Seller::factory()->create();

    expect(fn () => $this->ratings->reply($this->review, $otherSeller->user, $otherSeller, 'Not our problem.'))
        ->toThrow(ReplyNotAllowed::class);
});

it('will not let a reply carry contact details onto the page', function (): void {
    $this->ratings->reply($this->review, $this->sellerUser, $this->seller, 'Please call us on 0977123456 and we will sort it.');

    expect($this->review->refresh()->reply_body)
        ->not->toContain('0977123456')
        ->toContain('[removed]');
});

it('refuses a reply the screen would have queued rather than queueing it', function (): void {
    settings()->set('content.profanity_terms', ['liar']);

    expect(fn () => $this->ratings->reply($this->review, $this->sellerUser, $this->seller, 'This buyer is a liar about the part'))
        ->toThrow(ValidationException::class);

    expect($this->review->refresh()->hasReply())->toBeFalse();
});

it('has no reply to give on a private rating', function (): void {
    $order = Order::factory()->completed()->create([
        'user_id' => $this->buyer->getKey(),
        'seller_id' => $this->seller->getKey(),
    ]);

    $private = $this->ratings->submit($order, RatingDirection::SellerToBuyer, $this->sellerUser, 2, 'Late to collect.');

    expect(fn () => $this->ratings->reply($private, $this->buyer, $this->buyer, 'That is not fair at all.'))
        ->toThrow(ReplyNotAllowed::class);
});

it('closes the reply form once the reply is posted', function (): void {
    expect($this->sellerUser->can('reply', $this->review))->toBeTrue();

    $this->ratings->reply($this->review, $this->sellerUser, $this->seller, 'Replaced it the same day, sorry.');

    expect($this->sellerUser->can('reply', $this->review->refresh()))->toBeFalse();
});

it('posts the reply through the seller portal', function (): void {
    $this->actingAs($this->sellerUser)
        ->post(route('seller.ratings.reply', $this->review), [
            'reply' => 'Replaced the housing the same day. Sorry for the trouble.',
        ])
        ->assertRedirect();

    expect($this->review->refresh()->hasReply())->toBeTrue();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'rating.replied',
        'subject_id' => $this->review->getKey(),
    ]);
});

it('refuses the reply route to somebody else', function (): void {
    $stranger = User::factory()->withTwoFactor()->create();
    $stranger->assignRole(Role::Seller->value);
    Seller::factory()->for($stranger)->create();

    $this->actingAs($stranger)
        ->post(route('seller.ratings.reply', $this->review), ['reply' => 'Nothing to do with us at all.'])
        ->assertForbidden();

    expect(Rating::query()->whereNotNull('reply_body')->count())->toBe(0);
});
