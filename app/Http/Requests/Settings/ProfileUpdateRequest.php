<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Concerns\ResolvesAuthenticatedUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules, ResolvesAuthenticatedUser;

    /**
     * Uniqueness is checked against the E.164 form the column holds, so the
     * number has to be in that form before the rules run.
     */
    protected function prepareForValidation(): void
    {
        $this->merge($this->normalisePhone($this->all()));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->profileRules($this->user()->id);
    }
}
