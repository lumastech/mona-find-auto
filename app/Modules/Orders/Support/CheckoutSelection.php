<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Enums\FulfilmentMethod;

/**
 * What the buyer chose for one shop.
 *
 * A tiny object rather than three loose arguments because it travels from the
 * request, through validation, into the service and out into an order, and
 * every hop is a chance for the address to end up attached to the wrong
 * seller.
 */
final readonly class CheckoutSelection
{
    /**
     * @param  array<int, array{policy_id: int, version: int}>  $acceptedPolicies
     *                                                                             The ids and versions the buyer was actually shown. Checked
     *                                                                             against what is current at placement time, so a seller who
     *                                                                             republished a policy mid-checkout cannot end up with consent
     *                                                                             recorded for text nobody read.
     */
    public function __construct(
        public int $sellerId,
        public FulfilmentMethod $method,
        public ?int $addressId = null,
        public ?string $instructions = null,
        public bool $accepted = false,
        public array $acceptedPolicies = [],
    ) {}

    /**
     * The accepted versions as a comparable set: policy id => version.
     *
     * @return array<int, int>
     */
    public function acceptedVersions(): array
    {
        $versions = [];

        foreach ($this->acceptedPolicies as $policy) {
            $versions[(int) $policy['policy_id']] = (int) $policy['version'];
        }

        ksort($versions);

        return $versions;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            sellerId: (int) $input['seller_id'],
            method: FulfilmentMethod::from((string) $input['fulfilment_method']),
            addressId: isset($input['user_address_id']) ? (int) $input['user_address_id'] : null,
            instructions: isset($input['delivery_instructions']) && $input['delivery_instructions'] !== ''
                ? (string) $input['delivery_instructions']
                : null,
            accepted: (bool) ($input['accepted'] ?? false),
            acceptedPolicies: is_array($input['accepted_policies'] ?? null) ? $input['accepted_policies'] : [],
        );
    }

    /**
     * Index a set of selections by the seller they belong to.
     *
     * @param  array<int, array<string, mixed>>  $input
     * @return array<int, self>
     */
    public static function collect(array $input): array
    {
        $selections = [];

        foreach ($input as $row) {
            $selection = self::fromArray($row);
            $selections[$selection->sellerId] = $selection;
        }

        return $selections;
    }
}
