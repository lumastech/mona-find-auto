<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Exceptions;

use App\Modules\Sellers\Enums\RegistrationStep;
use RuntimeException;

/**
 * An application was submitted, or a step left, with something still missing.
 */
class RegistrationIncomplete extends RuntimeException
{
    public static function atStep(RegistrationStep $step): self
    {
        return new self(sprintf('Finish the "%s" step before you send your application.', $step->label()));
    }

    public static function noConfirmedPayoutAccount(): self
    {
        return new self('Add a payout account and let us confirm it before you carry on.');
    }

    /**
     * @param  array<int, string>  $missing
     */
    public static function missingDocuments(array $missing): self
    {
        return new self('Upload the documents we still need: '.implode(', ', $missing).'.');
    }

    /**
     * @param  array<int, string>  $missing
     */
    public static function missingPolicies(array $missing): self
    {
        return new self('Publish your '.implode(', ', $missing).' before you send your application.');
    }
}
