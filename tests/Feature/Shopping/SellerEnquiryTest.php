<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Services\MessagingEnquiryChannel;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Shopping\Contracts\SellerEnquiryChannel;
use App\Modules\Shopping\Events\SellerEnquired;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->enquiries = app(SellerEnquiryChannel::class);

    $this->buyer = User::factory()->create();
    $this->seller = Seller::factory()->ofType(SellerType::SparePartsShop)->create();
    $this->product = Product::factory()->ofSeller($this->seller)->create();
});

/*
 * StoredEnquiryChannel was the stub that kept "Contact seller" honest before
 * threads existed. Messaging has taken the job over, and these tests are the
 * proof that the handover did not change what the storefront promises: the
 * same call, the same reference back, the same event — a conversation behind
 * it instead of a write-only row.
 */
it('binds Messaging now that it has replaced the stored channel', function (): void {
    expect($this->enquiries)->toBeInstanceOf(MessagingEnquiryChannel::class);
});

it('records a message and hands back something to show the buyer', function (): void {
    Event::fake([SellerEnquired::class]);

    $reference = $this->enquiries->send($this->buyer, $this->seller, 'Do you deliver to Kabwe?', $this->product);

    expect($reference->message)->toBe('Do you deliver to Kabwe?')
        ->and($reference->id)->not->toBeEmpty()
        ->and(Message::query()->count())->toBe(1)
        ->and(MessageThread::query()->count())->toBe(1);

    Event::assertDispatched(SellerEnquired::class);
});

it('puts the buyer and the shop in the conversation, and nobody else', function (): void {
    $this->enquiries->send($this->buyer, $this->seller, 'Do you deliver to Kabwe?', $this->product);

    $thread = MessageThread::query()->sole();

    expect($thread->hasParticipant($this->buyer))->toBeTrue()
        ->and($thread->hasParticipant($this->seller->user))->toBeTrue()
        ->and($thread->hasParticipant(User::factory()->create()))->toBeFalse()
        ->and($thread->seller_id)->toBe($this->seller->id);
});

it('does not start a second conversation when the buyer writes again', function (): void {
    $this->enquiries->send($this->buyer, $this->seller, 'Do you deliver to Kabwe?', $this->product);
    $this->enquiries->send($this->buyer, $this->seller, 'Still interested.', $this->product);

    expect(MessageThread::query()->count())->toBe(1)
        ->and(MessageThread::query()->sole()->messages_count)->toBe(2);
});

it('counts what a buyer has already sent a shop', function (): void {
    $this->enquiries->send($this->buyer, $this->seller, 'First message.');
    $this->enquiries->send($this->buyer, $this->seller, 'Second message.');

    $stranger = User::factory()->create();

    expect($this->enquiries->countBetween($this->buyer, $this->seller))->toBe(2)
        ->and($this->enquiries->countBetween($stranger, $this->seller))->toBe(0);
});

it('lets a signed-in buyer write to a seller from a listing', function (): void {
    $this->actingAs($this->buyer)
        ->from(route('listings.show', $this->product))
        ->post(route('sellers.enquiries.store', $this->seller), [
            'message' => 'Is this the one with the wiring loom?',
            'product_id' => $this->product->id,
        ])
        ->assertRedirect(route('listings.show', $this->product));

    $thread = MessageThread::query()->sole();

    expect($thread->messages()->sole()->body)->toBe('Is this the one with the wiring loom?')
        ->and($thread->subject_id)->toBe($this->product->id)
        ->and($thread->subject_type)->toBe($this->product->getMorphClass())
        /* Unread for the shop, and not for the buyer who just wrote it. */
        ->and($thread->isUnreadFor($this->seller->user))->toBeTrue()
        ->and($thread->isUnreadFor($this->buyer))->toBeFalse();
});

it('sends a guest to log in rather than through to the seller', function (): void {
    $this->post(route('sellers.enquiries.store', $this->seller), ['message' => 'Hello?'])
        ->assertRedirect(route('login'));

    expect(MessageThread::query()->count())->toBe(0);
});

it('will not deliver a message to a shop that is not publicly visible', function (): void {
    $this->seller->forceFill(['verification_status' => VerificationStatus::Suspended])->save();

    $this->actingAs($this->buyer)
        ->post(route('sellers.enquiries.store', $this->seller), ['message' => 'Hello?'])
        ->assertNotFound();

    expect(MessageThread::query()->count())->toBe(0);
});

it('requires something to actually say', function (): void {
    $this->actingAs($this->buyer)
        ->post(route('sellers.enquiries.store', $this->seller), ['message' => ''])
        ->assertSessionHasErrors('message');
});

/*
|--------------------------------------------------------------------------
| The other half of "contact seller": who may read the details
|--------------------------------------------------------------------------
*/

it('masks the seller\'s contact details for a guest', function (): void {
    $this->get(route('listings.show', $this->product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('listing.seller.contact.visible', false)
            ->where('listing.seller.contact.prompt', 'Log in to view'))
        /* The masking is on the server: the real number is not in the page at all. */
        ->assertDontSee($this->seller->email);
});

it('shows the contact details to a signed-in buyer', function (): void {
    $this->actingAs($this->buyer)
        ->get(route('listings.show', $this->product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('listing.seller.contact.visible', true)
            ->where('listing.seller.contact.fields.1.value', $this->seller->email));
});
