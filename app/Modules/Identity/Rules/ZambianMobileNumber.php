<?php

declare(strict_types=1);

namespace App\Modules\Identity\Rules;

use App\Modules\Identity\Enums\MobileNetwork;
use App\Modules\Identity\Support\ZambianPhone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Accepts a Zambian mobile number in any shape a person might type it.
 *
 * Pair it with PhoneNumberCast on the model so what validates is also what
 * gets stored.
 */
class ZambianMobileNumber implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! ZambianPhone::isValid($value)) {
            $fail(sprintf(
                'The :attribute must be a Zambian mobile number starting %s.',
                $this->readablePrefixes(),
            ));
        }
    }

    /**
     * "096, 076, 097, 077, 095 or 075".
     */
    private function readablePrefixes(): string
    {
        $prefixes = array_map(
            static fn (string $prefix): string => '0'.$prefix,
            MobileNetwork::allPrefixes(),
        );

        $last = array_pop($prefixes);

        return implode(', ', $prefixes).' or '.$last;
    }
}
