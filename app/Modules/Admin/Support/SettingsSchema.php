<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Support\Money\Money;
use App\Support\Settings\SettingsRepository;
use App\Support\Settings\SettingType;
use Illuminate\Validation\Rule;

/**
 * What the settings console shows, and what it will accept.
 *
 * The `settings` table is deliberately untyped beyond a SettingType — it has
 * to be, because a module introduces its own keys. That leaves one question
 * unanswered: what is a LEGAL value? Nothing in the table says a ranking
 * weight cannot be 900, or that the "Ageing up to" window cannot be shorter
 * than the "Fresh up to" window it follows. Both of those are quietly
 * catastrophic — the first re-ranks the whole catalogue on one dimension, the
 * second makes a state unreachable — and neither is visible on the screen
 * where somebody types the number.
 *
 * So the ranges live here, next to the panel definitions, and the console
 * refuses anything outside them. Keys not listed below are not editable from
 * the console at all: a module with a key it does not want administrators
 * moving simply does not declare a panel for it.
 *
 * The platform terms are the notable absence. Their body and version number
 * are settings, but the CMS page owns them — publishing a new version of the
 * terms page bumps the version, and an administrator editing that text in a
 * settings box would change what buyers are shown without producing the new
 * version number their acceptance is recorded against.
 */
class SettingsSchema
{
    /**
     * Keys the console will never write, whatever a form posts.
     *
     * @var array<int, string>
     */
    public const READ_ONLY = [
        'policies.platform_terms_body',
        'policies.platform_terms_version',
    ];

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * Every panel, with each field's current value and its bounds.
     *
     * @return array<int, array{key: string, title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public function panels(): array
    {
        return array_values(array_map(
            fn (array $panel): array => [
                ...$panel,
                'fields' => array_values(array_map($this->hydrate(...), $panel['fields'])),
            ],
            $this->definitions(),
        ));
    }

    /**
     * The validation rules for whatever subset of keys was posted, keyed by
     * setting key.
     *
     * Callers must not hand these straight to the validator: a setting key
     * contains dots, and the validator reads a dot as a path into a nested
     * array. SettingsEditor rewrites them onto flat placeholders first.
     *
     * @param  array<int, string>  $keys
     * @return array<string, array<int, mixed>>
     */
    public function rulesFor(array $keys): array
    {
        $fields = $this->fieldsByKey();
        $rules = [];

        foreach ($keys as $key) {
            if (! isset($fields[$key]) || in_array($key, self::READ_ONLY, true)) {
                continue;
            }

            $rules[$key] = $fields[$key]['rules'];

            if (isset($fields[$key]['itemRules'])) {
                $rules[$key.'.*'] = $fields[$key]['itemRules'];
            }
        }

        return $rules;
    }

    public function isEditable(string $key): bool
    {
        return isset($this->fieldsByKey()[$key]) && ! in_array($key, self::READ_ONLY, true);
    }

    public function typeOf(string $key): SettingType
    {
        return $this->fieldsByKey()[$key]['type'] ?? SettingType::String;
    }

    public function labelOf(string $key): string
    {
        $field = $this->fieldsByKey()[$key] ?? null;

        return is_array($field) ? (string) $field['label'] : $key;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fieldsByKey(): array
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $fields = [];

        foreach ($this->definitions() as $panel) {
            foreach ($panel['fields'] as $field) {
                $fields[$field['key']] = $field;
            }
        }

        return $cached = $fields;
    }

    /**
     * Attach the stored value and drop the server-only rule objects.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function hydrate(array $field): array
    {
        $value = $this->settings->get((string) $field['key']);

        return [
            'key' => $field['key'],
            'label' => $field['label'],
            'help' => $field['help'] ?? null,
            'control' => $field['control'],
            'options' => $field['options'] ?? null,
            'min' => $field['min'] ?? null,
            'max' => $field['max'] ?? null,
            'suffix' => $field['suffix'] ?? null,
            'read_only' => in_array($field['key'], self::READ_ONLY, true),
            'value' => $this->presentable($value),
        ];
    }

    /**
     * Money decodes to a value object; the form wants a number of ngwee.
     */
    private function presentable(mixed $value): mixed
    {
        if ($value instanceof Money) {
            return $value->ngwee;
        }

        return $value;
    }

    /**
     * The panels themselves.
     *
     * @return array<int, array{key: string, title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    private function definitions(): array
    {
        return [
            [
                'key' => 'escrow',
                'title' => 'Escrow and auto-release',
                'description' => 'How long MonaFind holds a buyer\'s money before it releases to the seller on its own. An order already running keeps the window it was paid under.',
                'fields' => [
                    $this->integer('escrow.pickup_window_days', 'Pickup auto-complete window', 1, 30, 'days',
                        'Counted from collection. Below a day a buyer has no chance to check the part; above a month nobody remembers the purchase.'),
                    $this->integer('escrow.delivery_window_days', 'Delivery auto-complete window', 1, 45, 'days',
                        'Counted from dispatch, and normally longer than pickup because the part is still travelling.'),
                    $this->select('escrow.default_payment_mode', 'Default payment mode for new sellers', [
                        ['value' => 'escrow', 'label' => 'Escrow (recommended)'],
                        ['value' => 'direct', 'label' => 'Direct settlement'],
                    ], 'Direct settlement is meant to be earned per seller. Setting it as the default hands it to every new shop on the platform.'),
                ],
            ],
            [
                'key' => 'orders',
                'title' => 'Order handling',
                'description' => 'How long a seller has to answer, and what happens when they do not.',
                'fields' => [
                    $this->integer('orders.seller_confirm_window_hours', 'Seller confirmation window', 1, 168, 'hours',
                        'A paid order the seller has not accepted within this window is escalated.'),
                    $this->boolean('orders.auto_cancel_enabled', 'Auto-cancel unconfirmed orders',
                        'When off, an order a seller never confirms waits for a human instead of refunding itself.'),
                ],
            ],
            [
                'key' => 'freshness',
                'title' => 'Stock freshness',
                'description' => 'The clock every listing runs against. Each window must be longer than the one before it, or a state becomes unreachable.',
                'fields' => [
                    $this->integer('freshness.fresh_max_days', 'Fresh up to', 1, 30, 'days'),
                    $this->integer('freshness.ageing_max_days', 'Ageing up to', 1, 60, 'days',
                        'Must be longer than the Fresh window. Carries a small ranking demotion.'),
                    $this->integer('freshness.hidden_after_days', 'Hide listing after', 2, 120, 'days',
                        'Must be longer than the Ageing window. The listing leaves the storefront entirely.'),
                    $this->dayList('freshness.reminder_days', 'Stock reminder days',
                        'Days since the last confirmation at which the seller is reminded. Comma-separated.'),
                ],
            ],
            [
                'key' => 'ranking',
                'title' => 'Search quality weights',
                'description' => 'The six weights behind every listing\'s quality score. They decide which Zambian shop gets the phone call, so they are the most consequential numbers on this screen. Distance is never part of the default sort.',
                'fields' => [
                    $this->weight('ranking.weight.seller_rating', 'Seller rating'),
                    $this->weight('ranking.weight.review_count', 'Review count'),
                    $this->weight('ranking.weight.verified_seller', 'Verified seller'),
                    $this->weight('ranking.weight.inspected', 'Inspected listing'),
                    $this->weight('ranking.weight.freshness', 'Stock freshness'),
                    $this->weight('ranking.weight.low_disputes', 'Low dispute rate'),
                ],
            ],
            [
                'key' => 'monetisation',
                'title' => 'Monetisation defaults',
                'description' => 'What MonaFind charges a seller with no override of their own. The policy in force is snapshotted onto each order when it is paid, so changing these never touches an order already placed.',
                'fields' => [
                    $this->select('monetisation.commission_type', 'Commission type', [
                        ['value' => 'percentage', 'label' => 'Percentage of the order'],
                        ['value' => 'flat', 'label' => 'Flat amount per order'],
                    ]),
                    $this->percent('monetisation.commission_percent', 'Commission', 0, 40,
                        'Applied to the gross order amount when the type is percentage.'),
                    $this->money('monetisation.commission_flat', 'Flat commission',
                        'Applied per order when the type is flat.'),
                    $this->money('monetisation.addon_fee', 'Add-on fee',
                        'Deducted from the payout in addition to commission.'),
                    $this->percent('monetisation.referral_fee_percent', 'Referral fee', 0, 25,
                        'Charged when an order arrives through a referral partner.'),
                    $this->percent('monetisation.vat_on_commission_percent', 'VAT on commission', 0, 40,
                        'MonaFind invoices sellers for commission plus VAT at this rate. Product prices are VAT-inclusive and remain the seller\'s own responsibility.'),
                ],
            ],
            [
                'key' => 'risk',
                'title' => 'Reserves and risk',
                'description' => 'What a direct-settlement seller carries, and what sends one back to escrow.',
                'fields' => [
                    $this->percent('risk.reserve_percent', 'Rolling reserve', 0, 50,
                        'Share of trailing sales withheld from direct-settlement sellers.'),
                    $this->integer('risk.reserve_trailing_days', 'Reserve trailing window', 7, 180, 'days'),
                    $this->percent('risk.dispute_rate_threshold_percent', 'Dispute rate threshold', 0, 100,
                        'A direct-settlement seller above this rate reverts to escrow automatically.'),
                    $this->integer('risk.dispute_rate_min_orders', 'Minimum orders before the rate counts', 1, 500, 'orders',
                        'One dispute out of two orders is a 50% rate and means nothing.'),
                    $this->integer('risk.direct_min_completed_orders', 'Direct settlement: completed orders required', 0, 1000, 'orders'),
                    $this->percent('risk.direct_max_dispute_percent', 'Direct settlement: maximum dispute rate', 0, 100),
                    $this->integer('risk.direct_min_days_active', 'Direct settlement: days active required', 0, 730, 'days'),
                ],
            ],
            [
                'key' => 'payouts',
                'title' => 'Payouts and fees',
                'description' => 'Paying sellers, and who bears the cost of taking the money in.',
                'fields' => [
                    $this->integer('payouts.minimum_ngwee', 'Minimum payout', 0, 100_000_000, 'ngwee',
                        'Balances below this roll forward — a small payout can cost more in gateway fees than it moves.'),
                    $this->integer('payouts.settlement_overdue_days', 'Settlement overdue after', 1, 30, 'days',
                        'Reconciliation flags a collection Lenco has taken but not settled after this long.'),
                    $this->select('payments.fee_bearer', 'Who pays the Lenco collection fee', [
                        ['value' => 'merchant', 'label' => 'MonaFind absorbs it'],
                        ['value' => 'customer', 'label' => 'Added to what the buyer pays'],
                    ], 'Takes effect on the next payment. A buyer quoted K450 pays K450 while MonaFind bears it.'),
                ],
            ],
            [
                'key' => 'policies',
                'title' => 'Platform refund floor',
                'description' => 'The promise that overrides whatever a seller wrote in their own refund policy. It sits beside that policy on every seller page, so the two must say the same thing.',
                'fields' => [
                    $this->integer('policies.minimum_refund_days', 'Minimum refund window', 1, 30, 'days',
                        'A wrong or damaged item is refundable within this many days whatever the seller says.'),
                    $this->text('policies.minimum_refund_statement', 'Minimum refund statement', 400,
                        'Shown beside every seller refund policy. Keep the number in it in step with the window above.'),
                ],
            ],
            [
                'key' => 'ratings',
                'title' => 'Reviews and trust',
                'description' => 'What holds a review back, and which sellers land on the moderator\'s list.',
                'fields' => [
                    $this->integer('ratings.moderation.reports_before_review', 'Reports before a review is held back', 1, 20, 'reports',
                        'Abuse and leaked personal details hold a review on the first report whatever this says.'),
                    $this->decimal('ratings.review_list.min_average', 'Low-rating list: average below', 1, 5,
                        'A seller averaging less than this appears on the list whatever their dispute rate.'),
                    $this->integer('ratings.review_list.min_ratings', 'Low-rating list: minimum reviews', 1, 100, 'reviews'),
                    $this->weight('ratings.trust.weight.rating', 'Trust weight: average rating'),
                    $this->weight('ratings.trust.weight.volume', 'Trust weight: number of reviews'),
                    $this->weight('ratings.trust.weight.low_disputes', 'Trust weight: low dispute rate'),
                    $this->weight('ratings.trust.weight.fulfilment', 'Trust weight: orders completed'),
                ],
            ],
            [
                'key' => 'stock',
                'title' => 'Stock levels and imports',
                'description' => 'When a seller is warned they are running out, and how large a bulk upload may be.',
                'fields' => [
                    $this->integer('stock.low_stock_threshold', 'Default low-stock threshold', 0, 1000, 'units'),
                    $this->integer('stock.import_max_rows', 'Bulk stock upload row limit', 10, 50_000, 'rows'),
                ],
            ],
            [
                'key' => 'identity',
                'title' => 'One-time codes',
                'description' => 'SMS verification. Every one of these costs money per send, so the resend wait is a cost control as much as a security one.',
                'fields' => [
                    $this->integer('identity.otp_expiry_minutes', 'Code validity', 1, 60, 'minutes'),
                    $this->integer('identity.otp_max_attempts', 'Attempts allowed', 1, 10, 'attempts'),
                    $this->integer('identity.otp_resend_seconds', 'Resend wait', 15, 900, 'seconds'),
                    $this->integer('identity.max_delivery_addresses', 'Saved addresses per buyer', 1, 50, 'addresses'),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function integer(string $key, string $label, int $min, int $max, ?string $suffix = null, ?string $help = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'help' => $help,
            'control' => 'integer',
            'type' => SettingType::Integer,
            'min' => $min,
            'max' => $max,
            'suffix' => $suffix,
            'rules' => ['required', 'integer', 'min:'.$min, 'max:'.$max],
        ];
    }

    /**
     * A quality weight. Capped at 100 because the score is a weighted sum:
     * one weight an order of magnitude above the rest is not a preference,
     * it is that dimension deciding the whole ordering on its own.
     *
     * @return array<string, mixed>
     */
    private function weight(string $key, string $label): array
    {
        return $this->integer($key, $label, 0, 100, 'points');
    }

    /**
     * @return array<string, mixed>
     */
    private function percent(string $key, string $label, float $min, float $max, ?string $help = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'help' => $help,
            'control' => 'decimal',
            'type' => SettingType::Decimal,
            'min' => $min,
            'max' => $max,
            'suffix' => '%',
            'rules' => ['required', 'numeric', 'min:'.$min, 'max:'.$max, 'decimal:0,2'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decimal(string $key, string $label, float $min, float $max, ?string $help = null): array
    {
        return [
            ...$this->percent($key, $label, $min, $max, $help),
            'suffix' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function money(string $key, string $label, ?string $help = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'help' => $help,
            'control' => 'money',
            'type' => SettingType::Money,
            'min' => 0,
            'max' => 100_000_000,
            'suffix' => 'ngwee',
            'rules' => ['required', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function boolean(string $key, string $label, ?string $help = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'help' => $help,
            'control' => 'boolean',
            'type' => SettingType::Boolean,
            'rules' => ['required', 'boolean'],
        ];
    }

    /**
     * @param  array<int, array{value: string, label: string}>  $options
     * @return array<string, mixed>
     */
    private function select(string $key, string $label, array $options, ?string $help = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'help' => $help,
            'control' => 'select',
            'type' => SettingType::String,
            'options' => $options,
            'rules' => ['required', Rule::in(array_column($options, 'value'))],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function text(string $key, string $label, int $max, ?string $help = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'help' => $help,
            'control' => 'text',
            'type' => SettingType::String,
            'max' => $max,
            /* These are sentences shown to buyers, not labels: ten characters is not one. */
            'rules' => ['required', 'string', 'min:20', 'max:'.$max],
        ];
    }

    /**
     * A short ascending list of day numbers, typed as "3, 5".
     *
     * @return array<string, mixed>
     */
    private function dayList(string $key, string $label, ?string $help = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'help' => $help,
            'control' => 'day-list',
            'type' => SettingType::Array,
            'rules' => ['required', 'array', 'min:1', 'max:5'],
            'itemRules' => ['required', 'integer', 'min:1', 'max:120'],
        ];
    }
}
