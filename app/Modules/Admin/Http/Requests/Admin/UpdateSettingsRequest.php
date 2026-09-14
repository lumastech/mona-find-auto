<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests\Admin;

/**
 * Saving one panel of the settings console.
 *
 * Only the shape is checked here — that there are values, and a reason for
 * changing them. What each value may actually BE is SettingsSchema's answer,
 * applied by SettingsEditor, because the ranges have to be enforced
 * identically whether a change arrives from this form, a seeder or a test.
 */
class UpdateSettingsRequest extends ReasonedRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function actionRules(): array
    {
        return [
            'panel' => ['required', 'string', 'max:40'],
            'values' => ['required', 'array', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        /** @var array<string, mixed> $values */
        $values = $this->validated('values');

        return $values;
    }

    public function panel(): string
    {
        return (string) $this->validated('panel');
    }
}
