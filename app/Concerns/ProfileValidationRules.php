<?php

namespace App\Concerns;

use App\Modules\Identity\Support\AccountFieldRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Stringable;

/**
 * The rules behind the profile screen.
 *
 * The field definitions themselves belong to the Identity module — this trait
 * is the app-level entry point the settings requests already use.
 */
trait ProfileValidationRules
{
    use AccountFieldRules;

    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            ...$this->identityRules($userId),
            ...$this->addressRules(required: false),
        ];
    }
}
