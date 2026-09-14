<?php

declare(strict_types=1);

use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationChannel;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Messaging\Services\NotificationMatrix;
use App\Modules\Privacy\Notifications\ErasureCompletedNotification;
use Database\Seeders\SettingsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * The catalogue as a whole, rather than one notification at a time.
 *
 * Two of these are guards against a future change rather than tests of
 * today's behaviour, and that is deliberate: the failure they catch — a
 * notification that quietly stops being routable, an event that reaches
 * nobody — is silent in production and obvious here.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
});

/**
 * Every notification class the platform ships.
 *
 * @return array<int, class-string<Notification>>
 */
function platformNotificationClasses(): array
{
    $classes = [];

    foreach (Finder::create()->files()->in(app_path('Modules'))->path('Notifications')->name('*.php') as $file) {
        $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
        $class = 'App\\Modules\\'.$relative;

        if (class_exists($class) && is_subclass_of($class, Notification::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

it('finds the platform\'s notifications', function (): void {
    expect(platformNotificationClasses())->not->toBeEmpty();
});

it('routes every notification through the preference rules', function (): void {
    $unrouted = array_values(array_filter(
        platformNotificationClasses(),
        static fn (string $class): bool => ! is_subclass_of($class, PlatformNotification::class)
            || ! in_array(DeliversByPreference::class, class_uses_recursive($class), true),
    ));

    /*
     * A notification that defines its own via() is a notification nobody can
     * turn off and no administrator can see on the matrix — which is exactly
     * the state this module was built to end.
     */
    expect($unrouted)->toBe([]);
});

it('queues every notification, so nothing blocks the request that caused it', function (): void {
    $synchronous = array_values(array_filter(
        platformNotificationClasses(),
        static fn (string $class): bool => ! is_subclass_of($class, ShouldQueue::class),
    ));

    /*
     * One exception, and it has to be one.
     *
     * ErasureCompletedNotification is sent moments before the account it is
     * addressed to is anonymised. A queued notification serialises the
     * notifiable and re-fetches it in the worker, so by the time it ran the
     * address would be erased-91@erased.invalid and the person would never
     * hear that their deletion had happened.
     *
     * Sending it inline costs the erasure a few hundred milliseconds. That is
     * the whole price, and it is paid on a nightly sweep rather than in a
     * request somebody is waiting on.
     */
    expect($synchronous)->toBe([
        ErasureCompletedNotification::class,
    ]);
});

it('gives every notification the copy for each channel it will be routed to', function (): void {
    $missing = [];

    foreach (platformNotificationClasses() as $class) {
        /*
         * Instantiated without its constructor so this needs no fixtures.
         * notificationEvent() only returns an enum case — it touches none of
         * the properties the constructor would have set.
         */
        $event = (new ReflectionClass($class))->newInstanceWithoutConstructor()->notificationEvent();

        foreach ($event->defaultChannels() as $channel) {
            $method = match ($channel) {
                NotificationChannel::Database => 'toArray',
                NotificationChannel::Mail => 'toMail',
                NotificationChannel::Sms => 'toSms',
            };

            if (! method_exists($class, $method)) {
                $missing[] = class_basename($class).' needs '.$method.'() for '.$channel->value;
            }
        }
    }

    /*
     * The failure this catches is silent in production: the router picks SMS
     * off the matrix, the channel finds no toSms() and returns quietly, and
     * the notification looks sent. It cost a seller their new-order text
     * exactly once before this test existed.
     */
    expect($missing)->toBe([]);
});

it('gives every catalogue entry somewhere to go', function (): void {
    $silent = array_values(array_filter(
        NotificationEvent::cases(),
        static fn (NotificationEvent $event): bool => $event->defaultChannels() === [],
    ));

    expect($silent)->toBe([]);
});

it('spends SMS only where the message is worth it', function (): void {
    $texting = array_values(array_filter(
        NotificationEvent::cases(),
        static fn (NotificationEvent $event): bool => in_array(
            NotificationChannel::Sms,
            $event->defaultChannels(),
            true,
        ),
    ));

    /*
     * Pinned rather than merely counted. SMS costs money per message, and
     * this is the list somebody should have to justify changing — a new case
     * that quietly texts every buyer is the change worth catching in review.
     */
    expect($texting)->toEqualCanonicalizing([
        NotificationEvent::Otp,
        NotificationEvent::OrderPlaced,
        NotificationEvent::PaymentReceived,
        NotificationEvent::PayoutSent,
        NotificationEvent::DisputeOpened,
        NotificationEvent::DisputeResolved,
        NotificationEvent::QuotationRequested,
        NotificationEvent::StockConfirmationReminder,
        /*
         * The one notification worth texting somebody who is not expecting
         * it. A deletion scheduled by a stolen session is stopped by the
         * account holder noticing in time, and an SMS reaches a person who is
         * not reading the email on that account — which may be the point.
         */
        NotificationEvent::ErasureScheduled,
    ]);
});

it('keeps the mandatory list to the things an account cannot work without', function (): void {
    $mandatory = array_values(array_filter(
        NotificationEvent::cases(),
        static fn (NotificationEvent $event): bool => $event->isMandatory(),
    ));

    expect($mandatory)->toEqualCanonicalizing([
        NotificationEvent::Otp,
        NotificationEvent::AccountStatusChanged,
        NotificationEvent::AccountWarned,
        /*
         * The only route into a staff role. A matrix row switched off would
         * not annoy somebody, it would make it impossible to onboard a
         * moderator — and the message carries a one-time token that exists
         * nowhere else.
         */
        NotificationEvent::StaffInvited,
        /*
         * Both erasure notices. A person cannot opt out of being told that
         * their account is about to be destroyed: the scheduled notice is the
         * only thing standing between a hijacked session and an irreversible
         * deletion, and the completion notice is the record that reaches them
         * rather than only the audit trail.
         */
        NotificationEvent::ErasureScheduled,
        NotificationEvent::ErasureCompleted,
    ]);
});

it('seeds a matrix row for every event in the catalogue', function (): void {
    $matrix = app(NotificationMatrix::class)->toArray();

    foreach (NotificationEvent::cases() as $event) {
        expect($matrix)->toHaveKey($event->value);
    }
});

it('gives every event a label and a description a person can read', function (): void {
    foreach (NotificationEvent::cases() as $event) {
        expect($event->label())->not->toBe('')
            ->and($event->description())->not->toBe('')
            ->and($event->group())->not->toBe('');
    }
});
