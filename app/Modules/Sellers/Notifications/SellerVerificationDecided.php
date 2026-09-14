<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Notifications;

use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Modules\Messaging\Support\SmsMessage;
use App\Modules\Sellers\Enums\VerificationStatus;
use App\Modules\Sellers\Models\Seller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * What verification decided about a shop.
 *
 * The message a seller has been waiting days for, and the one they will
 * forward to whoever asked them to register. All three outcomes are here
 * rather than in three classes, because a seller experiences them as one
 * answer to one question.
 *
 * The reason travels with a refusal and with a suspension. A shop refused
 * without one has nothing to correct, and a shop suspended without one cannot
 * appeal — both of which turn into a support conversation that says the same
 * sentence this message could have.
 */
class SellerVerificationDecided extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(
        private readonly Seller $seller,
        private readonly VerificationStatus $status,
        private readonly ?string $reason = null,
    ) {}

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::SellerVerificationDecided;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->title())
            ->greeting('Hello '.$this->seller->business_name)
            ->line($this->body());

        if (filled($this->reason)) {
            $message->line('Reason: '.$this->reason);
        }

        return $message->action($this->actionLabel(), $this->url());
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return SmsMessage::make('MonaFind: '.$this->body())
            ->reference('seller:verification:'.$this->seller->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationEvent::SellerVerificationDecided->value,
            'seller_id' => $this->seller->getKey(),
            'status' => $this->status->value,
            'title' => $this->title(),
            'body' => $this->body(),
            'reason' => $this->reason,
            'action_url' => $this->url(),
            'action_label' => $this->actionLabel(),
        ];
    }

    private function title(): string
    {
        return match ($this->status) {
            VerificationStatus::Verified => $this->seller->business_name.' is verified',
            VerificationStatus::Rejected => 'Your MonaFind shop was not approved',
            VerificationStatus::Suspended => 'Your MonaFind shop has been suspended',
            default => 'Your MonaFind shop application has moved on',
        };
    }

    private function body(): string
    {
        return match ($this->status) {
            VerificationStatus::Verified => 'Your shop is verified. Your listings now carry the verified badge and rank higher in search.',
            VerificationStatus::Rejected => 'Your shop application was not approved. Correct the details and submit it again.',
            VerificationStatus::Suspended => 'Your shop is suspended. Your listings are hidden from buyers until it is restored.',
            VerificationStatus::InspectionScheduled => 'A MonaFind inspection of your premises has been scheduled.',
            default => 'Your shop application is now '.$this->status->label().'.',
        };
    }

    private function actionLabel(): string
    {
        return $this->status === VerificationStatus::Verified ? 'Open your shop' : 'Review your application';
    }

    private function url(): string
    {
        return route('seller.verification.show');
    }
}
