<?php

declare(strict_types=1);

use App\Modules\Payments\Enums\ReconciliationStatus;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Jobs\ReconcileGatewayDay;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\ReconciliationRun;
use App\Modules\Payments\Services\ReconciliationService;
use App\Support\Alerts\AlertDispatcher;
use App\Support\Alerts\AlertLevel;
use App\Support\Alerts\OperationalAlert;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    config()->set('security.alerts.recipients', 'ops@monafind.test');

    Notification::fake();
});

it('mails the configured operators', function (): void {
    app(AlertDispatcher::class)->raise(
        'test.thing',
        AlertLevel::Critical,
        'Something went wrong.',
        ['reference' => 'MFA-1-1'],
    );

    Notification::assertSentOnDemand(
        OperationalAlert::class,
        fn (OperationalAlert $alert, array $channels, object $notifiable): bool => $alert->level === AlertLevel::Critical
            && $notifiable->routes['mail'] === ['ops@monafind.test'],
    );
});

it('sends nothing when nobody is configured, which is right locally', function (): void {
    config()->set('security.alerts.recipients', '');

    expect(app(AlertDispatcher::class)->raise('test.thing', AlertLevel::Warning, 'Something.'))
        ->toBeFalse();

    Notification::assertNothingSent();
});

it('suppresses an identical alert inside its cooldown', function (): void {
    $alerts = app(AlertDispatcher::class);

    expect($alerts->raise('same.key', AlertLevel::Warning, 'Again.'))->toBeTrue()
        ->and($alerts->raise('same.key', AlertLevel::Warning, 'Again.'))->toBeFalse()
        ->and($alerts->raise('same.key', AlertLevel::Warning, 'Again.'))->toBeFalse();

    /*
     * Five hundred identical emails convey no more than one, because by the
     * hundredth the operator has moved them all to a folder.
     */
    Notification::assertSentOnDemandTimes(OperationalAlert::class, 1);
});

it('does not suppress a different alert', function (): void {
    $alerts = app(AlertDispatcher::class);

    $alerts->raise('one.key', AlertLevel::Warning, 'One.');
    $alerts->raise('other.key', AlertLevel::Warning, 'Other.');

    Notification::assertSentOnDemandTimes(OperationalAlert::class, 2);
});

it('tells the next alert how many it is standing for', function (): void {
    $alerts = app(AlertDispatcher::class);

    $alerts->raise('bursty', AlertLevel::Warning, 'First.');
    $alerts->raise('bursty', AlertLevel::Warning, 'Suppressed.');
    $alerts->raise('bursty', AlertLevel::Warning, 'Suppressed.');

    /* Past the cooldown, the count of what was swallowed rides along. */
    $this->travel(31)->minutes();

    $alerts->raise('bursty', AlertLevel::Warning, 'Next one through.');

    Notification::assertSentOnDemand(
        OperationalAlert::class,
        fn (OperationalAlert $alert): bool => ($alert->context['suppressed_since_last_alert'] ?? null) === 2,
    );
});

it('logs every occurrence even the ones it does not mail', function (): void {
    Log::spy();

    $alerts = app(AlertDispatcher::class);

    $alerts->raise('logged', AlertLevel::Critical, 'A thing.');
    $alerts->raise('logged', AlertLevel::Critical, 'A thing.');

    /* The log is the complete record; the mail is the interruption. */
    Log::shouldHaveReceived('critical')->twice();
});

it('never lets a broken mailer escalate into a failed job', function (): void {
    Notification::shouldReceive('route')->andThrow(new RuntimeException('SMTP is down'));

    expect(fn () => app(AlertDispatcher::class)->raise('x', AlertLevel::Critical, 'y'))
        ->not->toThrow(RuntimeException::class);
});

it('ignores a recipient that is not an address', function (): void {
    config()->set('security.alerts.recipients', 'ops@monafind.test, not-an-address, finance@monafind.test');

    expect(app(AlertDispatcher::class)->recipients())
        ->toBe(['ops@monafind.test', 'finance@monafind.test']);
});

it('alerts when a queued job fails, loudest on the money queue', function (): void {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Modules\Payments\Jobs\ExecutePayoutLine');
    $job->shouldReceive('getQueue')->andReturn(config('monafind.queues.payments'));
    $job->shouldReceive('attempts')->andReturn(3);
    /* Horizon's own failed-job listener asks for this. */
    $job->shouldReceive('getJobId')->andReturn('job-1');
    $job->shouldReceive('getConnectionName')->andReturn('redis');
    $job->shouldReceive('payload')->andReturn([]);
    $job->shouldReceive('uuid')->andReturn('uuid-1');

    event(new JobFailed('redis', $job, new RuntimeException('Transfer refused')));

    Notification::assertSentOnDemand(
        OperationalAlert::class,
        /*
         * A failed notification is worth knowing about; a failed payout is
         * worth interrupting somebody.
         */
        fn (OperationalAlert $alert): bool => $alert->level === AlertLevel::Critical
            && str_contains($alert->summary, 'ExecutePayoutLine'),
    );
});

it('alerts on a payment failure, keyed on the reason rather than the payment', function (): void {
    $first = Payment::factory()->create(['failure_reason' => 'Gateway authentication failed']);
    $second = Payment::factory()->create(['failure_reason' => 'Gateway authentication failed']);

    event(new PaymentFailed($first));
    event(new PaymentFailed($second));

    /*
     * One buyer's wrong PIN is not news. A reason that suddenly applies to
     * every attempt is — and it should arrive once, not once per buyer.
     */
    Notification::assertSentOnDemandTimes(OperationalAlert::class, 1);
});

it('alerts when a day does not reconcile', function (): void {
    $run = ReconciliationRun::factory()->create([
        'status' => ReconciliationStatus::Exceptions,
        'exception_count' => 3,
        'for_date' => now()->subDay()->toDateString(),
    ]);

    $service = Mockery::mock(ReconciliationService::class);
    $service->shouldReceive('run')->andReturn($run);

    (new ReconcileGatewayDay)->handle($service, app(AlertDispatcher::class));

    Notification::assertSentOnDemand(
        OperationalAlert::class,
        fn (OperationalAlert $alert): bool => $alert->level === AlertLevel::Critical
            && str_contains($alert->summary, '3 reconciliation exception'),
    );
});

it('stays quiet on a day that balanced', function (): void {
    $run = ReconciliationRun::factory()->create([
        'status' => ReconciliationStatus::Clean,
        'exception_count' => 0,
    ]);

    $service = Mockery::mock(ReconciliationService::class);
    $service->shouldReceive('run')->andReturn($run);

    (new ReconcileGatewayDay)->handle($service, app(AlertDispatcher::class));

    Notification::assertNothingSent();
});
