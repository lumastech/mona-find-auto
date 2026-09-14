<?php

declare(strict_types=1);

namespace App\Modules\Admin\Notifications;

use App\Models\User;
use App\Modules\Admin\Models\StaffInvitation;
use App\Modules\Messaging\Concerns\DeliversByPreference;
use App\Modules\Messaging\Contracts\PlatformNotification;
use App\Modules\Messaging\Enums\NotificationEvent;
use App\Support\Roles\Role;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation itself, carrying the only copy of the plaintext token.
 *
 * Mail only, and mandatory: NotificationEvent::StaffInvited is marked so, for
 * the same reason one-time codes and suspension notices are. This is not a
 * preference anybody expressed — it is the sole route into a staff account,
 * and a matrix row switched off must not silently swallow it.
 *
 * Sent on demand rather than to a User, because the invitee may not have an
 * account yet; that is also why there is no in-app copy to write.
 */
class StaffInvitationNotification extends Notification implements PlatformNotification, ShouldQueue
{
    use DeliversByPreference, Queueable;

    public function __construct(
        private readonly StaffInvitation $invitation,
        private readonly string $token,
    ) {
        $this->onQueue(config('monafind.queues.notifications'));
    }

    public function notificationEvent(): NotificationEvent
    {
        return NotificationEvent::StaffInvited;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $role = $this->invitation->roleEnum();

        return (new MailMessage)
            ->subject(__('You have been invited to the MonaFind staff console'))
            ->greeting(__('Hello :name,', ['name' => $this->invitation->name]))
            ->line(__(':inviter has invited you to join MonaFind as :role.', [
                'inviter' => $this->inviterName(),
                'role' => $role instanceof Role ? $role->label() : $this->invitation->role,
            ]))
            ->action(__('Accept the invitation'), route('staff-invitations.show', $this->token))
            /*
             * Said here rather than at the door, so nobody accepts on a phone
             * they cannot then enrol an authenticator on.
             */
            ->line(__('Staff accounts must use two-factor authentication. You will be asked to set it up before you can open the console.'))
            ->line(__('This invitation expires on :date.', [
                'date' => $this->invitation->expires_at
                    ->timezone((string) config('monafind.display_timezone'))
                    ->format('j F Y'),
            ]))
            ->line(__('If you were not expecting this, ignore it and tell us.'));
    }

    /**
     * Who sent it, or the platform when that account is long gone.
     */
    private function inviterName(): string
    {
        $inviter = $this->invitation->inviter;

        return $inviter instanceof User ? $inviter->name : 'A MonaFind administrator';
    }
}
