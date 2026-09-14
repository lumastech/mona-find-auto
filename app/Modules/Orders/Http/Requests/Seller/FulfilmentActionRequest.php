<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The optional note a seller attaches to a fulfilment step.
 *
 * "Left with the guard at the gate", "Called the buyer twice". It goes onto
 * the immutable status event, which is where a dispute about whether anybody
 * tried to hand a part over gets settled.
 */
class FulfilmentActionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) && $note !== '' ? $note : null;
    }
}
