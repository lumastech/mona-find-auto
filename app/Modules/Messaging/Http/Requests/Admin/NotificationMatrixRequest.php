<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Requests\Admin;

use App\Modules\Messaging\Enums\NotificationChannel;
use App\Modules\Messaging\Enums\NotificationEvent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The whole grid, plus why it changed.
 *
 * The reason is required and it is not ceremony: this setting decides whether
 * a class of message reaches anybody, and "SMS stopped working in March" is a
 * question somebody will ask. It goes into the audit row.
 *
 * Mandatory events are stripped before validation rather than rejected. A
 * form that posts one is a stale tab, not an attack, and refusing the whole
 * submission would lose the changes the administrator actually made.
 */
class NotificationMatrixRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'matrix' => ['required', 'array'],
            'matrix.*' => ['array'],
            'matrix.*.*' => ['string', 'in:'.implode(',', NotificationChannel::values())],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why this is changing — it goes on the audit trail.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $submitted = $this->input('matrix');

        if (! is_array($submitted)) {
            return;
        }

        $clean = [];

        foreach ($submitted as $key => $channels) {
            $event = NotificationEvent::tryFrom((string) $key);

            /* Unknown, or not the administrator's to change. */
            if ($event === null || $event->isMandatory() || ! is_array($channels)) {
                continue;
            }

            $clean[$event->value] = array_values(array_filter(
                array_map(static fn (mixed $channel): string => (string) $channel, $channels),
                static fn (string $channel): bool => NotificationChannel::tryFrom($channel) !== null,
            ));
        }

        $this->merge(['matrix' => $clean]);
    }
}
