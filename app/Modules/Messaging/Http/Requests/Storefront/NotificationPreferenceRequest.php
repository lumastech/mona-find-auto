<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Requests\Storefront;

use App\Modules\Messaging\Enums\NotificationChannel;
use App\Modules\Messaging\Enums\NotificationEvent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The whole preference screen, posted at once.
 *
 * `preferences[orders.placed][sms] = false`. All of it rather than the one
 * switch that moved, because a partial payload cannot distinguish "left
 * alone" from "switched off" — and the service treats what it is given as the
 * complete answer.
 *
 * The keys are validated against the catalogue so that a stale browser tab
 * cannot write rows for an event that no longer exists. The service narrows
 * further still, dropping mandatory events and matrix-disabled channels.
 */
class NotificationPreferenceRequest extends FormRequest
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
            'preferences' => ['required', 'array'],
            'preferences.*' => ['array'],
        ];
    }

    /**
     * Drop anything the catalogue does not recognise before it reaches the
     * service.
     */
    protected function prepareForValidation(): void
    {
        $submitted = $this->input('preferences');

        if (! is_array($submitted)) {
            return;
        }

        $clean = [];

        foreach ($submitted as $event => $channels) {
            if (NotificationEvent::tryFrom((string) $event) === null || ! is_array($channels)) {
                continue;
            }

            foreach ($channels as $channel => $enabled) {
                if (NotificationChannel::tryFrom((string) $channel) === null) {
                    continue;
                }

                $clean[(string) $event][(string) $channel] = filter_var($enabled, FILTER_VALIDATE_BOOL);
            }
        }

        $this->merge(['preferences' => $clean]);
    }
}
