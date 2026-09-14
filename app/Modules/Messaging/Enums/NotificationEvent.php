<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Enums;

/**
 * Every kind of thing MonaFind tells somebody about.
 *
 * One closed list, in one place, for two reasons. The admin matrix is a grid
 * of events against channels and it has to be able to draw itself without a
 * developer maintaining a second list; and a person's notification
 * preferences are rows keyed by these values, so a case renamed carelessly
 * would silently reset everybody's choices — hence the values are written out
 * rather than derived from the case names.
 *
 * Other modules name their event here and implement PlatformNotification.
 * That is the whole of their coupling to Messaging: Orders does not know what
 * a channel is, and Messaging does not know what an order is.
 *
 * MANDATORY events ignore preferences entirely. A verification code nobody
 * receives is an account nobody can get into, and a suspension notice a
 * suspended account has opted out of is a support ticket — so those go out
 * whatever the person has asked for. Everything else is theirs to turn off.
 */
enum NotificationEvent: string
{
    /* Security — always delivered, never configurable away. */

    /** A six-digit code, for registration, sign-in or a password reset. */
    case Otp = 'security.otp';

    /** The account was suspended, restored or closed. */
    case AccountStatusChanged = 'security.account_status_changed';

    /** Staff issued a formal warning against the account. */
    case AccountWarned = 'security.account_warned';

    /** The only route into a staff role, carrying a one-time token. */
    case StaffInvited = 'security.staff_invited';

    /**
     * An erasure has been scheduled against the account.
     *
     * Mandatory, and the reason it is mandatory is the case it exists for: a
     * stolen session asking for the account to be deleted. The email is what
     * reaches the real account holder while the grace period is still running
     * and the request can still be called off.
     */
    case ErasureScheduled = 'security.erasure_scheduled';

    /** The erasure has been carried out. The last message this address gets. */
    case ErasureCompleted = 'security.erasure_completed';

    /* Account and verification outcomes. */

    case AccountRegistered = 'account.registered';
    case SellerVerificationDecided = 'account.seller_verification_decided';
    case MechanicApprovalDecided = 'account.mechanic_approval_decided';
    case EndorsementRequested = 'account.endorsement_requested';
    case EndorsementDecided = 'account.endorsement_decided';

    /* Listings and stock. */

    case ListingModerated = 'listings.moderated';
    case StockConfirmationReminder = 'listings.stock_confirmation_reminder';
    case StockLow = 'listings.stock_low';
    case StockBackIn = 'listings.back_in_stock';

    /* Orders. */

    case OrderPlaced = 'orders.placed';
    case OrderStateChanged = 'orders.state_changed';
    case DisputeOpened = 'orders.dispute_opened';
    case DisputeUpdated = 'orders.dispute_updated';
    case DisputeResolved = 'orders.dispute_resolved';

    /* Money. */

    case PaymentReceived = 'money.payment_received';
    case PayoutSent = 'money.payout_sent';
    case PaymentModeReverted = 'money.payment_mode_reverted';

    /* Conversations and reputation. */

    case QuotationRequested = 'messages.quotation_requested';
    case QuotationAnswered = 'messages.quotation_answered';
    case MessageReceived = 'messages.received';
    case RatingInvited = 'reviews.invited';
    case RatingReceived = 'reviews.received';

    /**
     * Whether this goes out however the person has set their preferences.
     */
    public function isMandatory(): bool
    {
        return match ($this) {
            self::Otp,
            self::AccountStatusChanged,
            self::AccountWarned,
            self::StaffInvited,
            self::ErasureScheduled,
            self::ErasureCompleted => true,
            default => false,
        };
    }

    /**
     * The channels this event uses on a fresh install.
     *
     * These are defaults, not rules — the admin matrix overrides them and the
     * stored matrix is what actually routes. SMS is spent where the message
     * is worth a few ngwee and time matters: money moved, an order arrived, a
     * dispute opened. It is not spent on "somebody replied".
     *
     * @return array<int, NotificationChannel>
     */
    public function defaultChannels(): array
    {
        return match ($this) {
            self::Otp => [NotificationChannel::Sms],

            /*
             * Mail only. The recipient has no account yet, so there is no bell
             * to write to — and the message carries a one-time token, which is
             * a credential and has no business sitting in a table forever. The
             * same reasoning keeps the OTP off the database channel.
             */
            self::StaffInvited => [NotificationChannel::Mail],

            self::AccountStatusChanged,
            self::AccountWarned => [NotificationChannel::Database, NotificationChannel::Mail],

            /*
             * Mail and SMS, no database row. The bell belongs to an account
             * that is about to stop existing, and the whole point of the
             * message is to reach a person who may not be the one holding the
             * session that asked for it.
             */
            self::ErasureScheduled => [NotificationChannel::Mail, NotificationChannel::Sms],

            /* Mail only: by delivery time the phone number is a tombstone. */
            self::ErasureCompleted => [NotificationChannel::Mail],

            self::OrderPlaced,
            self::PaymentReceived,
            self::PayoutSent,
            self::DisputeOpened,
            self::DisputeResolved,
            self::QuotationRequested,
            self::StockConfirmationReminder => [
                NotificationChannel::Database,
                NotificationChannel::Mail,
                NotificationChannel::Sms,
            ],

            default => [NotificationChannel::Database, NotificationChannel::Mail],
        };
    }

    /**
     * The heading this event sits under on the matrix and preference screens.
     */
    public function group(): string
    {
        return match ($this) {
            self::Otp,
            self::AccountStatusChanged,
            self::AccountWarned,
            self::StaffInvited,
            self::ErasureScheduled,
            self::ErasureCompleted => 'Security',
            self::AccountRegistered,
            self::SellerVerificationDecided,
            self::MechanicApprovalDecided,
            self::EndorsementRequested,
            self::EndorsementDecided => 'Account',
            self::ListingModerated,
            self::StockConfirmationReminder,
            self::StockLow,
            self::StockBackIn => 'Listings and stock',
            self::OrderPlaced,
            self::OrderStateChanged,
            self::DisputeOpened,
            self::DisputeUpdated,
            self::DisputeResolved => 'Orders',
            self::PaymentReceived,
            self::PayoutSent,
            self::PaymentModeReverted => 'Money',
            self::QuotationRequested,
            self::QuotationAnswered,
            self::MessageReceived => 'Messages',
            self::RatingInvited, self::RatingReceived => 'Reviews',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Otp => 'Verification code',
            self::AccountStatusChanged => 'Account suspended or restored',
            self::AccountWarned => 'Formal warning',
            self::StaffInvited => 'Staff invitation',
            self::ErasureScheduled => 'Account deletion scheduled',
            self::ErasureCompleted => 'Account deleted',
            self::AccountRegistered => 'Welcome and account verification',
            self::SellerVerificationDecided => 'Shop verification outcome',
            self::MechanicApprovalDecided => 'Mechanic profile outcome',
            self::EndorsementRequested => 'Endorsement requested',
            self::EndorsementDecided => 'Endorsement outcome',
            self::ListingModerated => 'Listing approved or rejected',
            self::StockConfirmationReminder => 'Confirm your stock',
            self::StockLow => 'Running low on stock',
            self::StockBackIn => 'Back in stock',
            self::OrderPlaced => 'New order',
            self::OrderStateChanged => 'Order progress',
            self::DisputeOpened => 'Dispute opened',
            self::DisputeUpdated => 'Dispute updated',
            self::DisputeResolved => 'Dispute resolved',
            self::PaymentReceived => 'Payment received',
            self::PayoutSent => 'Payout sent',
            self::PaymentModeReverted => 'Payment mode changed',
            self::QuotationRequested => 'Quote requested',
            self::QuotationAnswered => 'Quote answered',
            self::MessageReceived => 'New message',
            self::RatingInvited => 'Invitation to leave a review',
            self::RatingReceived => 'New review',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Otp => 'The code needed to verify a number or reset a password.',
            self::AccountStatusChanged => 'Sent when staff suspend, restore or close an account.',
            self::AccountWarned => 'Sent when staff record a warning against an account.',
            self::StaffInvited => 'Sent when a platform administrator invites somebody onto the staff console.',
            self::ErasureScheduled => 'Sent when an account deletion is requested, saying when it will happen and how to stop it.',
            self::ErasureCompleted => 'Sent once the account has been deleted and its personal data removed.',
            self::AccountRegistered => 'Sent once, when an account is created.',
            self::SellerVerificationDecided => 'Sent to a shop when verification is granted, refused or withdrawn.',
            self::MechanicApprovalDecided => 'Sent to a mechanic when their public profile is approved, refused or suspended.',
            self::EndorsementRequested => 'Sent to a shop when a mechanic asks it to endorse them.',
            self::EndorsementDecided => 'Sent to a mechanic when a shop answers an endorsement request.',
            self::ListingModerated => 'Sent to a shop when a listing is published or rejected.',
            self::StockConfirmationReminder => 'Sent when listings have not been confirmed for several days.',
            self::StockLow => 'Sent when a variant falls to its low-stock threshold.',
            self::StockBackIn => 'Sent to buyers waiting for a sold-out listing.',
            self::OrderPlaced => 'Sent to a shop when a paid order arrives.',
            self::OrderStateChanged => 'Sent to a buyer as their order is confirmed, dispatched and completed.',
            self::DisputeOpened => 'Sent when a buyer raises a dispute on an order.',
            self::DisputeUpdated => 'Sent when evidence or a note is added to an open dispute.',
            self::DisputeResolved => 'Sent when staff decide a dispute.',
            self::PaymentReceived => 'Sent when a payment settles against an order.',
            self::PayoutSent => 'Sent to a shop when money leaves MonaFind for their account.',
            self::PaymentModeReverted => 'Sent when a shop is moved back to escrow.',
            self::QuotationRequested => 'Sent to a shop when a buyer asks for a price.',
            self::QuotationAnswered => 'Sent to a buyer when a shop answers with a price.',
            self::MessageReceived => 'Sent when somebody writes in a conversation you are part of.',
            self::RatingInvited => 'Sent after an order completes, asking both sides to rate each other.',
            self::RatingReceived => 'Sent when somebody reviews you.',
        };
    }

    /**
     * The events a person may configure, grouped for the preference screen.
     *
     * Mandatory events are shown there too — a person is entitled to know a
     * code will be texted to them — but they are shown as fixed rather than
     * as a switch that does nothing.
     *
     * @return array<string, array<int, self>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::cases() as $event) {
            $grouped[$event->group()][] = $event;
        }

        return $grouped;
    }
}
