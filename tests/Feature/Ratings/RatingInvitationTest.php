<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Orders\Enums\FulfilmentMethod;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderStateMachine;
use App\Modules\Orders\Support\OrderActor;
use App\Modules\Ratings\Notifications\RatingInvitation;
use App\Modules\Sellers\Models\Seller;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    Notification::fake();

    $this->buyer = User::factory()->create();
    $this->sellerUser = User::factory()->create();
    $this->seller = Seller::factory()->for($this->sellerUser)->create();
});

it('asks both sides to rate once the order completes', function (): void {
    $order = Order::factory()
        ->handedOver()
        ->create([
            'user_id' => $this->buyer->getKey(),
            'seller_id' => $this->seller->getKey(),
            'fulfilment_method' => FulfilmentMethod::Pickup,
        ]);

    app(OrderStateMachine::class)->complete(
        $order,
        OrderActor::forUser($this->buyer, $order),
        'Confirmed by the buyer.',
    );

    Notification::assertSentTo($this->buyer, RatingInvitation::class);
    Notification::assertSentTo($this->sellerUser, RatingInvitation::class);
});

it('asks nobody while the order is still running', function (): void {
    $order = Order::factory()->paid()->create([
        'user_id' => $this->buyer->getKey(),
        'seller_id' => $this->seller->getKey(),
    ]);

    app(OrderStateMachine::class)->confirm(
        $order,
        OrderActor::forUser($this->sellerUser, $order),
        'We have it.',
    );

    /*
     * Orders sends its own "the shop has your order" message here; what must
     * not go out is the invitation to rate.
     */
    Notification::assertNotSentTo($this->buyer, RatingInvitation::class);
    Notification::assertNotSentTo($this->sellerUser, RatingInvitation::class);
});
