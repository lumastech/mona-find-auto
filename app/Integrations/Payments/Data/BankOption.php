<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Data;

/**
 * A bank the gateway can pay out to.
 *
 * Sellers pick from this list rather than typing a bank name, because
 * resolution and transfers are both addressed by the gateway's code.
 */
final readonly class BankOption
{
    public function __construct(
        public string $code,
        public string $name,
    ) {}

    /**
     * @return array{code: string, name: string}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'name' => $this->name];
    }
}
