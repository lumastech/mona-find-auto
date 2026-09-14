<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

/**
 * How a seller measures against the direct-settlement criteria.
 *
 * A read-only verdict with its workings attached. The admin screen shows each
 * criterion separately rather than one yes/no, because an administrator
 * deciding whether to override needs to see WHICH test failed — "verified but
 * only 14 orders" and "40 orders but a 6% dispute rate" are very different
 * risks and only one of them is about patience.
 */
final readonly class DirectEligibility
{
    public function __construct(
        public bool $isVerified,
        public int $completedOrders,
        public int $requiredOrders,
        public float $disputeRatePercent,
        public float $maximumDisputeRatePercent,
        public int $daysActive,
        public int $requiredDaysActive,
    ) {}

    public function hasEnoughOrders(): bool
    {
        return $this->completedOrders >= $this->requiredOrders;
    }

    public function hasAcceptableDisputeRate(): bool
    {
        return $this->disputeRatePercent < $this->maximumDisputeRatePercent;
    }

    public function isEstablished(): bool
    {
        return $this->daysActive >= $this->requiredDaysActive;
    }

    /**
     * Whether MonaFind would recommend direct settlement unprompted.
     *
     * An administrator may still decide otherwise; this is advice, not a gate.
     */
    public function isEligible(): bool
    {
        return $this->isVerified
            && $this->hasEnoughOrders()
            && $this->hasAcceptableDisputeRate()
            && $this->isEstablished();
    }

    /**
     * The criteria, each with its own verdict, for the admin screen.
     *
     * @return array<int, array{key: string, label: string, met: bool, detail: string}>
     */
    public function criteria(): array
    {
        return [
            [
                'key' => 'verified',
                'label' => __('Verified seller'),
                'met' => $this->isVerified,
                'detail' => $this->isVerified ? __('Verified') : __('Not yet verified'),
            ],
            [
                'key' => 'orders',
                'label' => __('Completed orders'),
                'met' => $this->hasEnoughOrders(),
                'detail' => __(':count of :required', [
                    'count' => $this->completedOrders,
                    'required' => $this->requiredOrders,
                ]),
            ],
            [
                'key' => 'disputes',
                'label' => __('Dispute rate'),
                'met' => $this->hasAcceptableDisputeRate(),
                'detail' => __(':rate% (must be under :max%)', [
                    'rate' => number_format($this->disputeRatePercent, 2),
                    'max' => number_format($this->maximumDisputeRatePercent, 2),
                ]),
            ],
            [
                'key' => 'tenure',
                'label' => __('Time active'),
                'met' => $this->isEstablished(),
                'detail' => __(':days of :required days', [
                    'days' => $this->daysActive,
                    'required' => $this->requiredDaysActive,
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_eligible' => $this->isEligible(),
            'is_verified' => $this->isVerified,
            'completed_orders' => $this->completedOrders,
            'required_orders' => $this->requiredOrders,
            'dispute_rate_percent' => round($this->disputeRatePercent, 2),
            'maximum_dispute_rate_percent' => $this->maximumDisputeRatePercent,
            'days_active' => $this->daysActive,
            'required_days_active' => $this->requiredDaysActive,
            'criteria' => $this->criteria(),
        ];
    }
}
