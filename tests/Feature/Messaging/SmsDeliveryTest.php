<?php

declare(strict_types=1);

use App\Contracts\SmsProvider;
use App\Models\User;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Messaging\Enums\NotificationChannel;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Messaging\Models\NotificationPreference;
use App\Modules\Messaging\Services\NotificationMatrix;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Notifications\OrderPaidNotification;
use App\Modules\Sellers\Models\Seller;
use Database\Seeders\SettingsSeeder;

/**
 * End to end, with nothing faked between the notification and the network.
 *
 * The other tests drive the channel directly, which proves the channel. These
 * prove the WIRING: that `$user->notify(...)` picks the SMS channel out of the
 * matrix, resolves the channel class, and reaches SmsProvider. A mistake
 * anywhere in that chain is silent — the notification appears to send and no
 * text arrives.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);

    $this->seller = Seller::factory()->create();
    $this->seller->user->forceFill(['phone_verified_at' => now()])->save();
});

it('texts a seller when a paid order arrives', function (): void {
    $order = Order::factory()->forSeller($this->seller)->create(['paid_at' => now()]);

    $this->seller->user->notify(new OrderPaidNotification($order));

    $messages = app(SmsProvider::class)->messagesTo($this->seller->user->phone);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['message'])->toContain($order->number)
        ->and($messages[0]['sender_id'])->toBe(app(SmsProvider::class)->defaultSenderId());
});

it('sends no text once the seller has turned that one off', function (): void {
    NotificationPreference::factory()->muted()->create([
        'user_id' => $this->seller->user_id,
        'event' => NotificationEvent::OrderPlaced,
        'channel' => NotificationChannel::Sms,
    ]);

    $order = Order::factory()->forSeller($this->seller)->create(['paid_at' => now()]);

    $this->seller->user->notify(new OrderPaidNotification($order));

    expect(app(SmsProvider::class)->messagesTo($this->seller->user->phone))->toBeEmpty()
        /* Still in the bell, though: narrowing a channel is not silence. */
        ->and($this->seller->user->notifications()->count())->toBe(1);
});

it('sends no text once staff have turned that one off platform-wide', function (): void {
    app(NotificationMatrix::class)->replace([
        NotificationEvent::OrderPlaced->value => [NotificationChannel::Database->value],
    ], null, 'SMS spend is over budget.');

    $order = Order::factory()->forSeller($this->seller)->create(['paid_at' => now()]);

    $this->seller->user->notify(new OrderPaidNotification($order));

    expect(app(SmsProvider::class)->messagesTo($this->seller->user->phone))->toBeEmpty();
});

it('texts a verification code to a number with no account behind it', function (): void {
    app(OtpService::class)->issue('0977123456', OtpPurpose::PhoneVerification);

    $messages = app(SmsProvider::class)->messagesTo('+260977123456');

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['reference'])->toBe('otp:phone_verification')
        ->and($messages[0]['message'])->toMatch('/\b\d{6}\b/');
});

it('texts a verification code even to somebody who has opted out of everything', function (): void {
    $user = User::factory()->create(['phone' => '+260977123456', 'phone_verified_at' => now()]);

    foreach (NotificationEvent::cases() as $event) {
        foreach (NotificationChannel::cases() as $channel) {
            NotificationPreference::factory()->muted()->create([
                'user_id' => $user->id,
                'event' => $event,
                'channel' => $channel,
            ]);
        }
    }

    app(OtpService::class)->issue($user->phone, OtpPurpose::PasswordReset, $user);

    expect(app(SmsProvider::class)->messagesTo('+260977123456'))->toHaveCount(1);
});
