<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff putting a deletion on hold.
 *
 * The reason is required and is shown to the account holder, not just written
 * to the audit trail. Somebody whose deletion has stalled is entitled to know
 * what is holding it up and, usually, to do something about it — so "open
 * dispute on order MF-7QK4ZP2A" is the standard to write to, and "pending"
 * is not.
 *
 * Privacy declares its own rule rather than extending Admin's ReasonedRequest:
 * modules do not inherit from each other's request classes, and a five-line
 * rule is a smaller cost than the coupling.
 */
class BlockErasureRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
