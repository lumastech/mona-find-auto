<?php

declare(strict_types=1);

use App\Contracts\SmsProvider;
use App\Models\User;
use App\Modules\Messaging\Channels\SmsChannel;
use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\NotificationRouter;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationChannel;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Messaging\Models\NotificationPreference;
use App\Modules\Messaging\Services\NotificationMatrix;
use App\Modules\Messaging\Support\SmsMessage;
use App\Support\Roles\Role;
use Database\Seeders\SettingsSeeder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Where a notification goes: the matrix, then the person, then whether they
 * can be reached at all.
 */
beforeEach(function (): void {
    /* The matrix is a setting, so the definitions have to exist. */
    $this->seed(SettingsSeeder::class);

    $this->user = User::factory()->create(['phone_verified_at' => now()]);
    $this->router = app(NotificationRouter::class);
    $this->matrix = app(NotificationMatrix::class);
});

/** A notification that exists only to be routed. */
function routableNotification(NotificationEvent $event): BaseNotification
{
    return new class($event) extends BaseNotification implements PlatformNotification
    {
        use DeliversByPreference;

        public function __construct(private readonly NotificationEvent $event) {}

        public function notificationEvent(): NotificationEvent
        {
            return $this->event;
        }

        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)->line('Test.');
        }

        public function toSms(object $notifiable): SmsMessage
        {
            return SmsMessage::make('MonaFind: test message.')->reference('test');
        }

        /**
         * @return array<string, mixed>
         */
        public function toArray(object $notifiable): array
        {
            return ['type' => $this->event->value, 'title' => 'Test', 'body' => 'Test.'];
        }
    };
}

/*
|--------------------------------------------------------------------------
| The matrix
|--------------------------------------------------------------------------
*/

it('sends an event only on the channels the matrix allows', function (): void {
    $this->matrix->replace([
        NotificationEvent::OrderPlaced->value => [NotificationChannel::Database->value],
    ], null, 'Testing.');

    expect($this->router->channelsFor(NotificationEvent::OrderPlaced, $this->user))
        ->toBe([NotificationChannel::Database]);
});

it('falls back to an event\'s own defaults when the matrix has never heard of it', function (): void {
    settings()->set(NotificationMatrix::SETTING_KEY, []);

    expect($this->matrix->channelsFor(NotificationEvent::PayoutSent))
        ->toBe(NotificationEvent::PayoutSent->defaultChannels());
});

it('keeps the events a stale form did not mention', function (): void {
    $this->matrix->replace([
        NotificationEvent::OrderPlaced->value => [NotificationChannel::Database->value],
    ], null, 'Testing.');

    $before = $this->matrix->channelsFor(NotificationEvent::PayoutSent);

    /* A form that only knows about one row must not clear the rest. */
    $this->matrix->replace([
        NotificationEvent::OrderStateChanged->value => [NotificationChannel::Mail->value],
    ], null, 'Testing again.');

    expect($this->matrix->channelsFor(NotificationEvent::PayoutSent))->toBe($before)
        ->and($this->matrix->channelsFor(NotificationEvent::OrderPlaced))
        ->toBe([NotificationChannel::Database]);
});

it('writes an audit row when staff change the routing', function (): void {
    $admin = actingAsRole([Role::PlatformAdmin]);

    $this->matrix->replace([
        NotificationEvent::OrderPlaced->value => [NotificationChannel::Database->value],
    ], $admin, 'SMS costs are too high this month.');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'notifications.matrix_updated',
        'actor_id' => $admin->id,
        'reason' => 'SMS costs are too high this month.',
    ]);
});

/*
|--------------------------------------------------------------------------
| Preferences
|--------------------------------------------------------------------------
*/

it('respects an opt-out', function (): void {
    NotificationPreference::factory()->muted()->create([
        'user_id' => $this->user->id,
        'event' => NotificationEvent::OrderStateChanged,
        'channel' => NotificationChannel::Sms,
    ]);

    $this->matrix->replace([
        NotificationEvent::OrderStateChanged->value => NotificationChannel::values(),
    ], null, 'Testing.');

    expect($this->router->channelsFor(NotificationEvent::OrderStateChanged, $this->user))
        ->not->toContain(NotificationChannel::Sms)
        ->toContain(NotificationChannel::Mail);
});

it('ignores an opt-out from a security event', function (): void {
    /*
     * The row is allowed to exist — the preference screen will not write one,
     * but an API caller could. The router simply does not read it.
     */
    NotificationPreference::factory()->muted()->create([
        'user_id' => $this->user->id,
        'event' => NotificationEvent::AccountStatusChanged,
        'channel' => NotificationChannel::Mail,
    ]);

    expect($this->router->channelsFor(NotificationEvent::AccountStatusChanged, $this->user))
        ->toContain(NotificationChannel::Mail);
});

it('never lets a preference switch on a channel the matrix has switched off', function (): void {
    $this->matrix->replace([
        NotificationEvent::OrderStateChanged->value => [NotificationChannel::Database->value],
    ], null, 'Testing.');

    /* An explicitly enabled preference is still narrower than the ceiling. */
    NotificationPreference::factory()->create([
        'user_id' => $this->user->id,
        'event' => NotificationEvent::OrderStateChanged,
        'channel' => NotificationChannel::Sms,
        'enabled' => true,
    ]);

    expect($this->router->channelsFor(NotificationEvent::OrderStateChanged, $this->user))
        ->toBe([NotificationChannel::Database]);
});

/*
|--------------------------------------------------------------------------
| Reachability
|--------------------------------------------------------------------------
*/

it('does not text an account whose number has never been verified', function (): void {
    $unverified = User::factory()->create(['phone_verified_at' => null]);

    $this->matrix->replace([
        NotificationEvent::OrderPlaced->value => NotificationChannel::values(),
    ], null, 'Testing.');

    expect($this->router->channelsFor(NotificationEvent::OrderPlaced, $unverified))
        ->not->toContain(NotificationChannel::Sms)
        ->toContain(NotificationChannel::Mail);
});

it('has no in-app copy for an on-demand notification with nowhere to put one', function (): void {
    $anonymous = Notification::route('sms', '+260977123456');

    expect($this->router->channelsFor(NotificationEvent::Otp, $anonymous))
        ->toBe([NotificationChannel::Sms]);
});

/*
|--------------------------------------------------------------------------
| The SMS channel
|--------------------------------------------------------------------------
*/

it('hands the message, the reference and the sender ID to the provider', function (): void {
    config()->set('integrations.sms.sender_id', 'MonaFindZM');

    app(SmsChannel::class)->send($this->user, routableNotification(NotificationEvent::OrderPlaced));

    $messages = app(SmsProvider::class)->messagesTo($this->user->phone);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['message'])->toBe('MonaFind: test message.')
        ->and($messages[0]['reference'])->toBe('test')
        ->and($messages[0]['sender_id'])->toBe('MonaFindZM');
});

it('lets a notification override the sender ID', function (): void {
    config()->set('integrations.sms.sender_id', 'MonaFindZM');

    app(SmsProvider::class)->send('+260977123456', 'Direct.', 'ref', 'MonaFindOTP');

    expect(app(SmsProvider::class)->messagesTo('+260977123456')[0]['sender_id'])->toBe('MonaFindOTP');
});

it('collapses a multi-line body onto one line and caps its length', function (): void {
    $message = SmsMessage::make("First line.\n\nSecond   line.".str_repeat(' padding', 100));

    expect($message->body())->not->toContain("\n")
        ->and(mb_strlen($message->body()))->toBeLessThanOrEqual(SmsMessage::MAX_LENGTH + 3);
});

it('sends nothing rather than failing when there is no number to send to', function (): void {
    $noPhone = User::factory()->create(['phone' => null, 'phone_verified_at' => null]);

    app(SmsChannel::class)->send($noPhone, routableNotification(NotificationEvent::OrderPlaced));

    expect(app(SmsProvider::class)->messages())->toBeEmpty();
});
