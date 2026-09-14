<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Modules\Catalog\Enums\Condition;
use App\Modules\Sellers\Enums\SellerType;
use RuntimeException;

/**
 * A seller tried to list under a condition their type of business cannot use.
 *
 * In practice this is a car breaker: everything they sell came off a scrapped
 * vehicle, so "Brand New" is not a mistake to correct quietly — it is a claim
 * a buyer would rely on.
 */
class ConditionNotAllowed extends RuntimeException
{
    public function __construct(
        public readonly SellerType $sellerType,
        public readonly Condition $attempted,
        public readonly Condition $required,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forSellerType(SellerType $type, Condition $attempted, Condition $required): self
    {
        return new self($type, $attempted, $required, sprintf(
            'A %s lists everything as %s, so this cannot be marked %s.',
            $type->label(),
            $required->label(),
            $attempted->label(),
        ));
    }
}
