<?php

declare(strict_types=1);

namespace App\Support\Alerts;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One message to the people who run the platform.
 *
 * Deliberately not a PlatformNotification. Those are routed through the
 * preference matrix, which is about what an account holder has asked to
 * receive — and an operator cannot be allowed to switch off the alert that
 * says payouts have stopped. These go to configured addresses, unconditionally.
 *
 * The queue is the `notifications` one, so an alert cannot sit behind a
 * backlog of payment webhooks — which is precisely the situation an alert is
 * most likely to be describing.
 */
class OperationalAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public readonly AlertLevel $level,
        public readonly string $summary,
        public readonly array $context = [],
        public readonly ?string $actionUrl = null,
        public readonly ?string $actionLabel = null,
    ) {
        $this->onQueue(config('monafind.queues.notifications'));
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->level->subjectPrefix().' '.$this->summary)
            ->line($this->summary);

        foreach ($this->context as $key => $value) {
            $message->line(sprintf('%s: %s', $this->humanise($key), $this->stringify($value)));
        }

        $message->line('Environment: '.app()->environment());

        if ($this->actionUrl !== null) {
            $message->action($this->actionLabel ?? 'Open the console', $this->actionUrl);
        }

        return $message;
    }

    private function humanise(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'yes' : 'no',
            default => (string) $value,
        };
    }
}
