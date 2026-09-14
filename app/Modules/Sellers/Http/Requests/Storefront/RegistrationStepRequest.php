<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Requests\Storefront;

use App\Modules\Sellers\Enums\RegistrationStep;
use App\Modules\Sellers\Enums\SellerType;
use App\Modules\Sellers\Support\SellerFieldRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Stringable;

/**
 * One step of the seller sign-up wizard.
 *
 * The step being saved decides which rules apply, and for the business step
 * the *type* chosen on step one decides them too — a garage is asked how many
 * bays it has, a parts retailer is not. That is why the type is read back off
 * the draft rather than trusted from the form: somebody who changes it in the
 * payload must not be able to skip the questions their type asks.
 */
class RegistrationStepRequest extends FormRequest
{
    use SellerFieldRules;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    public function rules(): array
    {
        return match ($this->step()) {
            RegistrationStep::Type => $this->typeStepRules(),
            RegistrationStep::Business => $this->businessStepRules($this->chosenType(), $this->sellerId()),
            RegistrationStep::Policies => $this->policiesStepRules(),
            /* Payout and documents are posted to their own endpoints, which validate them. */
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->sellerFieldMessages();
    }

    protected function prepareForValidation(): void
    {
        if ($this->step() === RegistrationStep::Business) {
            $this->merge($this->normaliseSellerPhones($this->all()));
        }
    }

    /**
     * Which step is being saved.
     */
    public function step(): RegistrationStep
    {
        $step = $this->route('step');

        return $step instanceof RegistrationStep
            ? $step
            : (RegistrationStep::tryFrom((string) $step) ?? RegistrationStep::first());
    }

    /**
     * The validated answers, ready to be written onto the draft.
     *
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        return $this->validated();
    }

    /**
     * The business type this application is for. On step one it is whatever
     * was just chosen; afterwards it is whatever the draft already holds.
     */
    private function chosenType(): ?SellerType
    {
        $draft = $this->user()?->sellerRegistrationDraft;

        return $draft?->sellerType();
    }

    /**
     * The seller row behind this draft, once there is one, so uniqueness
     * checks ignore the applicant's own record when they go back and edit.
     */
    private function sellerId(): ?int
    {
        return $this->user()?->sellerRegistrationDraft?->seller_id;
    }
}
