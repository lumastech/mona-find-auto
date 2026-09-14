<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Messaging\Services\NotificationMatrix;
use App\Support\Settings\SettingsRepository;
use App\Support\Settings\SettingType;
use Illuminate\Database\Seeder;

/**
 * The platform's configurable defaults.
 *
 * Everything the brief describes as "configurable in admin" starts here.
 * Re-running the seeder updates each setting's definition (label, type,
 * group) without disturbing a value an administrator has since changed —
 * except on a fresh install, where the value below is what takes effect.
 */
class SettingsSeeder extends Seeder
{
    public function run(SettingsRepository $settings): void
    {
        foreach ($this->definitions() as $key => $definition) {
            [$value, $type, $group, $label, $description, $isPublic] = [
                $definition['value'],
                $definition['type'],
                $definition['group'],
                $definition['label'],
                $definition['description'] ?? null,
                $definition['is_public'] ?? false,
            ];

            if ($settings->has($key)) {
                /** Keep the administrator's value; refresh only its definition. */
                $value = $settings->get($key);
            }

            $settings->define($key, $value, [
                'type' => $type,
                'group' => $group,
                'label' => $label,
                'description' => $description,
                'is_public' => $isPublic,
            ]);
        }
    }

    /**
     * @return array<string, array{value: mixed, type: SettingType, group: string, label: string, description?: string, is_public?: bool}>
     */
    private function definitions(): array
    {
        return [

            /*
             * Escrow — how long the platform holds a buyer's money before it
             * auto-completes in the seller's favour.
             */
            'escrow.pickup_window_days' => [
                'value' => 3,
                'type' => SettingType::Integer,
                'group' => 'escrow',
                'label' => 'Pickup auto-complete window (days)',
                'description' => 'Days after collection before a pickup order auto-completes and escrow releases.',
                'is_public' => true,
            ],
            'escrow.delivery_window_days' => [
                'value' => 7,
                'type' => SettingType::Integer,
                'group' => 'escrow',
                'label' => 'Delivery auto-complete window (days)',
                'description' => 'Days after dispatch before a delivery order auto-completes and escrow releases.',
                'is_public' => true,
            ],
            'escrow.default_payment_mode' => [
                'value' => 'escrow',
                'type' => SettingType::String,
                'group' => 'escrow',
                'label' => 'Default payment mode for new sellers',
                'description' => 'Either "escrow" or "direct". New sellers always start on escrow unless changed here.',
            ],

            /*
             * Stock freshness — sellers confirm stock every few days, and
             * listings are demoted and eventually hidden when they do not.
             */
            'freshness.fresh_max_days' => [
                'value' => 3,
                'type' => SettingType::Integer,
                'group' => 'freshness',
                'label' => 'Fresh up to (days)',
                'description' => 'Stock confirmed within this many days is labelled Fresh.',
                'is_public' => true,
            ],
            'freshness.ageing_max_days' => [
                'value' => 5,
                'type' => SettingType::Integer,
                'group' => 'freshness',
                'label' => 'Ageing up to (days)',
                'description' => 'Beyond Fresh and up to this many days is Ageing, which carries a small ranking demotion.',
                'is_public' => true,
            ],
            'freshness.hidden_after_days' => [
                'value' => 14,
                'type' => SettingType::Integer,
                'group' => 'freshness',
                'label' => 'Hide listing after (days)',
                'description' => 'Unconfirmed listings are hidden from the storefront after this many days.',
            ],
            'freshness.reminder_days' => [
                'value' => [3, 5],
                'type' => SettingType::Array,
                'group' => 'freshness',
                'label' => 'Stock reminder days',
                'description' => 'Days since the last confirmation at which a seller is reminded, in ascending order.',
            ],

            /*
             * Stock levels — when a seller is warned they are running out,
             * and how large a bulk upload may be.
             */
            'stock.low_stock_threshold' => [
                'value' => 2,
                'type' => SettingType::Integer,
                'group' => 'stock',
                'label' => 'Default low-stock threshold',
                'description' => 'Quantity at or below which a variant counts as low stock. Sellers may override it per variant.',
                'is_public' => true,
            ],
            'stock.import_max_rows' => [
                'value' => 2000,
                'type' => SettingType::Integer,
                'group' => 'stock',
                'label' => 'Bulk stock upload row limit',
                'description' => 'Rows accepted in one bulk stock file. Larger files are rejected before anything is applied.',
            ],

            /*
             * Search ranking — weights inside a match tier. Distance is never
             * part of the default sort.
             */
            'ranking.weight.seller_rating' => [
                'value' => 30,
                'type' => SettingType::Integer,
                'group' => 'ranking',
                'label' => 'Quality weight: seller rating',
            ],
            'ranking.weight.review_count' => [
                'value' => 10,
                'type' => SettingType::Integer,
                'group' => 'ranking',
                'label' => 'Quality weight: review count',
            ],
            'ranking.weight.verified_seller' => [
                'value' => 20,
                'type' => SettingType::Integer,
                'group' => 'ranking',
                'label' => 'Quality weight: verified seller',
            ],
            'ranking.weight.inspected' => [
                'value' => 15,
                'type' => SettingType::Integer,
                'group' => 'ranking',
                'label' => 'Quality weight: inspected listing',
            ],
            'ranking.weight.freshness' => [
                'value' => 15,
                'type' => SettingType::Integer,
                'group' => 'ranking',
                'label' => 'Quality weight: stock freshness',
            ],
            'ranking.weight.low_disputes' => [
                'value' => 10,
                'type' => SettingType::Integer,
                'group' => 'ranking',
                'label' => 'Quality weight: low dispute rate',
            ],

            /*
             * Monetisation defaults. Each seller may be given an override;
             * the applicable policy is snapshotted onto every order.
             */
            'monetisation.commission_type' => [
                'value' => 'percentage',
                'type' => SettingType::String,
                'group' => 'monetisation',
                'label' => 'Default commission type',
                'description' => 'Either "percentage" or "flat".',
            ],
            'monetisation.commission_percent' => [
                'value' => '7.50',
                'type' => SettingType::Decimal,
                'group' => 'monetisation',
                'label' => 'Default commission (%)',
                'description' => 'Applied to the gross order amount when the commission type is percentage.',
            ],
            'monetisation.commission_flat' => [
                'value' => 0,
                'type' => SettingType::Money,
                'group' => 'monetisation',
                'label' => 'Default flat commission',
                'description' => 'Applied per order when the commission type is flat. Stored in ngwee.',
            ],
            'monetisation.addon_fee' => [
                'value' => 0,
                'type' => SettingType::Money,
                'group' => 'monetisation',
                'label' => 'Default add-on fee',
                'description' => 'Deducted from the seller payout in addition to commission. Stored in ngwee.',
            ],
            'monetisation.referral_fee_percent' => [
                'value' => '0.00',
                'type' => SettingType::Decimal,
                'group' => 'monetisation',
                'label' => 'Default referral fee (%)',
                'description' => 'Charged when an order arrives through a referral partner.',
            ],
            'monetisation.vat_on_commission_percent' => [
                'value' => '16.00',
                'type' => SettingType::Decimal,
                'group' => 'monetisation',
                'label' => 'VAT on commission (%)',
                'description' => 'MonaFind invoices sellers for commission plus VAT at this rate. Product prices are VAT-inclusive and are the seller\'s own responsibility.',
            ],

            /*
             * Reserves and risk — direct-payment sellers carry a rolling
             * reserve and revert to escrow if their dispute rate climbs.
             */
            'risk.reserve_percent' => [
                'value' => '10.00',
                'type' => SettingType::Decimal,
                'group' => 'risk',
                'label' => 'Direct-seller rolling reserve (%)',
                'description' => 'Percentage of trailing sales withheld from direct-payment sellers.',
            ],
            'risk.reserve_trailing_days' => [
                'value' => 30,
                'type' => SettingType::Integer,
                'group' => 'risk',
                'label' => 'Reserve trailing window (days)',
                'description' => 'The reserve is calculated against sales in this trailing window.',
            ],
            'risk.dispute_rate_threshold_percent' => [
                'value' => '2.00',
                'type' => SettingType::Decimal,
                'group' => 'risk',
                'label' => 'Dispute rate threshold (%)',
                'description' => 'A direct-payment seller above this dispute rate reverts to escrow automatically.',
            ],
            'risk.dispute_rate_min_orders' => [
                'value' => 10,
                'type' => SettingType::Integer,
                'group' => 'risk',
                'label' => 'Minimum orders before the dispute rate counts',
                'description' => 'A seller with fewer paid orders than this is never auto-reverted — one dispute out of two orders is a 50% rate and means nothing.',
            ],
            'risk.direct_min_completed_orders' => [
                'value' => 20,
                'type' => SettingType::Integer,
                'group' => 'risk',
                'label' => 'Direct settlement: completed orders required',
                'description' => 'Recommended minimum before a seller is offered direct settlement. An administrator may override with a reason.',
            ],
            'risk.direct_max_dispute_percent' => [
                'value' => '2.00',
                'type' => SettingType::Decimal,
                'group' => 'risk',
                'label' => 'Direct settlement: maximum dispute rate (%)',
                'description' => 'Recommended ceiling on a seller\'s dispute rate before direct settlement is offered.',
            ],
            'risk.direct_min_days_active' => [
                'value' => 60,
                'type' => SettingType::Integer,
                'group' => 'risk',
                'label' => 'Direct settlement: days active required',
                'description' => 'Recommended minimum time a seller has been active before direct settlement is offered.',
            ],

            /*
             * Payouts. Sellers are paid from a batch that one member of
             * Finance builds and another approves.
             */
            'payouts.minimum_ngwee' => [
                'value' => 5000,
                'type' => SettingType::Integer,
                'group' => 'payouts',
                'label' => 'Minimum payout (ngwee)',
                'description' => 'Balances below this roll forward to the next run — a small payout can cost more in gateway fees than it moves.',
            ],
            'payments.fee_bearer' => [
                'value' => 'merchant',
                'type' => SettingType::String,
                'group' => 'payouts',
                'label' => 'Who pays the Lenco collection fee',
                'description' => 'Either "merchant" (MonaFind absorbs it, and a buyer quoted K450 pays K450) or "customer" (the fee is added at the widget). Changing this changes what buyers are charged on their next payment.',
            ],
            'payouts.settlement_overdue_days' => [
                'value' => 3,
                'type' => SettingType::Integer,
                'group' => 'payouts',
                'label' => 'Settlement overdue after (days)',
                'description' => 'Reconciliation flags a collection that Lenco has taken but not settled to MonaFind after this many days.',
            ],

            /*
             * Ratings, reviews and trust. The word list and the report
             * threshold are the two an administrator actually reaches for;
             * the trust weights are the equivalent of ranking.weight.* for
             * the moderator's review list.
             */
            /*
             * Notifications — which events go out over which channels.
             *
             * The lever that matters operationally: SMS costs money per
             * message and the bill arrives after the fact, so switching a row
             * off here stops those texts platform-wide without a deployment.
             * An event missing from the stored grid falls back to its own
             * defaults, so adding one to the catalogue works on the next
             * deploy rather than going silently undelivered.
             */
            NotificationMatrix::SETTING_KEY => [
                'value' => NotificationMatrix::defaults(),
                'type' => SettingType::Array,
                'group' => 'notifications',
                'label' => 'Notification routing',
                'description' => 'Which channels each kind of notification uses. Verification codes and account suspensions ignore this and are always sent.',
            ],

            'content.profanity_terms' => [
                'value' => ['fuck', 'shit', 'bastard', 'bitch', 'idiot', 'thief', 'fraudster', 'scammer'],
                'type' => SettingType::Array,
                'group' => 'ratings',
                'label' => 'Words that hold a review for review',
                'description' => 'A review containing any of these waits for a moderator instead of publishing. Contact details are redacted automatically and are not on this list.',
            ],
            'ratings.moderation.reports_before_review' => [
                'value' => 3,
                'type' => SettingType::Integer,
                'group' => 'ratings',
                'label' => 'Reports before a review is held back',
                'description' => 'Abuse and leaked personal details hold a review back on the first report; everything else waits for this many.',
            ],
            'ratings.review_list.min_average' => [
                'value' => '3.00',
                'type' => SettingType::Decimal,
                'group' => 'ratings',
                'label' => 'Low-rating review list: average below',
                'description' => 'A seller averaging less than this appears on the moderator review list, whatever their dispute rate.',
            ],
            'ratings.review_list.min_ratings' => [
                'value' => 3,
                'type' => SettingType::Integer,
                'group' => 'ratings',
                'label' => 'Low-rating review list: minimum reviews',
                'description' => 'A seller with fewer reviews than this is never listed on rating alone — one unhappy buyer out of two is not a pattern.',
            ],
            'ratings.trust.weight.rating' => [
                'value' => 50,
                'type' => SettingType::Integer,
                'group' => 'ratings',
                'label' => 'Trust weight: average rating',
            ],
            'ratings.trust.weight.volume' => [
                'value' => 15,
                'type' => SettingType::Integer,
                'group' => 'ratings',
                'label' => 'Trust weight: number of reviews',
            ],
            'ratings.trust.weight.low_disputes' => [
                'value' => 25,
                'type' => SettingType::Integer,
                'group' => 'ratings',
                'label' => 'Trust weight: low dispute rate',
            ],
            'ratings.trust.weight.fulfilment' => [
                'value' => 10,
                'type' => SettingType::Integer,
                'group' => 'ratings',
                'label' => 'Trust weight: orders completed',
                'description' => 'The share of a seller\'s paid orders that finished rather than being cancelled or refunded.',
            ],

            /*
             * Seller policies. Sellers write their own delivery, refund,
             * warranty and terms; the platform floor below sits beside the
             * refund policy on every seller page and cannot be contracted
             * out of, so a buyer reads both in the same place.
             */
            'policies.minimum_refund_days' => [
                'value' => 3,
                'type' => SettingType::Integer,
                'group' => 'policies',
                'label' => 'Platform minimum refund window (days)',
                'description' => 'A wrong or damaged item is refundable within this many days whatever the seller policy says.',
                'is_public' => true,
            ],
            'policies.platform_terms_version' => [
                'value' => '1',
                'type' => SettingType::String,
                'group' => 'policies',
                'label' => 'Platform terms version',
                'description' => 'Bumped whenever MonaFind\'s own terms of use change. Recorded against every order acceptance, so buyers can be shown what they agreed to.',
                'is_public' => true,
            ],
            'policies.platform_terms_body' => [
                'value' => 'MonaFindAuto is a marketplace. Each part is sold by the seller listed against it, and that seller is responsible for the goods, for their description and for VAT on them. MonaFind verifies sellers, holds payments in escrow unless stated otherwise, and decides disputes raised before an order completes. Prices shown include VAT. MonaFind charges the seller a commission and is not a party to the sale itself.',
                'type' => SettingType::String,
                'group' => 'policies',
                'label' => 'Platform terms of use',
                'description' => 'Shown in the checkout acceptance modal beside each seller\'s own policies.',
                'is_public' => true,
            ],
            'policies.minimum_refund_statement' => [
                'value' => 'Whatever this seller\'s policy says, MonaFind refunds a wrong or damaged item reported within 3 days of you receiving it.',
                'type' => SettingType::String,
                'group' => 'policies',
                'label' => 'Platform minimum refund statement',
                'description' => 'Shown beside every seller refund policy. Keep it in step with the window above.',
                'is_public' => true,
            ],

            /*
             * Order handling.
             */
            'orders.seller_confirm_window_hours' => [
                'value' => 24,
                'type' => SettingType::Integer,
                'group' => 'orders',
                'label' => 'Seller confirmation window (hours)',
                'description' => 'How long a seller has to accept a paid order before it is escalated.',
                'is_public' => true,
            ],

            'orders.auto_cancel_enabled' => [
                'value' => true,
                'type' => SettingType::Boolean,
                'group' => 'orders',
                'label' => 'Auto-cancel unconfirmed orders',
                'description' => 'When on, a paid order the seller never confirms is cancelled and the buyer refunded.',
            ],

            /*
             * Identity — one-time codes, and how long a person has to use one.
             */
            'identity.otp_expiry_minutes' => [
                'value' => 10,
                'type' => SettingType::Integer,
                'group' => 'identity',
                'label' => 'OTP validity (minutes)',
                'description' => 'How long an SMS verification code stays usable after it is sent.',
                'is_public' => true,
            ],
            'identity.otp_max_attempts' => [
                'value' => 3,
                'type' => SettingType::Integer,
                'group' => 'identity',
                'label' => 'OTP attempts allowed',
                'description' => 'Incorrect guesses before a code is burnt and a new one must be requested.',
            ],
            'identity.otp_resend_seconds' => [
                'value' => 60,
                'type' => SettingType::Integer,
                'group' => 'identity',
                'label' => 'OTP resend wait (seconds)',
                'description' => 'How long a person must wait before another code can be sent to the same number.',
                'is_public' => true,
            ],
            'identity.max_delivery_addresses' => [
                'value' => 10,
                'type' => SettingType::Integer,
                'group' => 'identity',
                'label' => 'Saved delivery addresses per buyer',
                'description' => 'Upper bound on the addresses one buyer may keep in their address book.',
            ],

            /*
             * Privacy — the Data Protection Act 2021 obligations that have a
             * number in them. The retention figures are the ones documented
             * in docs/DATA_RETENTION.md; changing one here without changing
             * the schedule there makes the published notice untrue.
             */
            'privacy.erasure_grace_days' => [
                'value' => 14,
                'type' => SettingType::Integer,
                'group' => 'privacy',
                'label' => 'Account deletion grace period (days)',
                'description' => 'How long a deletion request waits before it is carried out, so an account holder can call it off.',
                'is_public' => true,
            ],
            'privacy.search_history_days' => [
                'value' => 90,
                'type' => SettingType::Integer,
                'group' => 'privacy',
                'label' => 'Search history retention (days)',
                'description' => 'How long a signed-in buyer\'s search queries stay attached to their account before being detached.',
            ],
            'privacy.financial_retention_years' => [
                'value' => 10,
                'type' => SettingType::Integer,
                'group' => 'privacy',
                'label' => 'Financial record retention (years)',
                'description' => 'How long orders, payments and ledger entries are kept, as required by Zambian tax and company law.',
            ],
            'privacy.controller_contact' => [
                'value' => 'privacy@monafind.co.zm',
                'type' => SettingType::String,
                'group' => 'privacy',
                'label' => 'Data protection contact',
                'description' => 'The address published on the privacy notice for data-subject requests and complaints.',
                'is_public' => true,
            ],

        ];
    }
}
