<?php

declare(strict_types=1);

namespace App\Support\Settings;

use InvalidArgumentException;

class InvalidSettingValue extends InvalidArgumentException
{
    public function __construct(SettingType $type, mixed $value)
    {
        parent::__construct(sprintf(
            'A value of type [%s] cannot be stored in a [%s] setting.',
            get_debug_type($value),
            $type->value,
        ));
    }
}
