<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Requests\Admin;

use App\Modules\Sellers\Models\Seller;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Stringable;

/**
 * A reviewer's decision on a seller's application.
 *
 * The reason is mandatory on anything that goes against the seller, and has a
 * floor on its length: "no" in an audit row is no use to the seller trying to
 * fix it, nor to whoever reviews the decision later.
 */
class VerificationDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $seller = $this->route('seller');

        return $seller instanceof Seller && $this->user()?->can('verify', $seller) === true;
    }

    /**
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2000'],
            'checklist' => ['nullable', 'array'],
            'checklist.*' => ['boolean'],
        ];
    }

    /**
     * The reviewer's checklist notes, kept on the seller's file.
     */
    public function note(): ?string
    {
        $note = $this->string('note')->trim()->toString();

        return $note === '' ? null : $note;
    }

    /**
     * Which checks were ticked.
     *
     * @return array<string, bool>
     */
    public function checklist(): array
    {
        /** @var array<string, mixed> $checklist */
        $checklist = $this->array('checklist');

        return array_map(static fn (mixed $ticked): bool => (bool) $ticked, $checklist);
    }

    public function inspectionDate(): ?CarbonInterface
    {
        $when = $this->input('inspection_scheduled_for');

        return is_string($when) && $when !== '' ? Carbon::parse($when) : null;
    }
}
