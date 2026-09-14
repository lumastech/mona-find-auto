<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Support\SettingsSchema;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Applies a settings form, validating it against the schema first.
 *
 * Two things happen here that a form request could not do on its own.
 *
 * The first is the key rewriting. A setting key contains dots, and Laravel's
 * validator reads a dot as a path into a nested array — a rule on
 * `values.freshness.fresh_max_days` would go looking for an array called
 * `freshness`. The posted values are therefore moved onto flat placeholders,
 * validated, and the failures mapped back onto the names the form used.
 *
 * The second is the cross-field rules, which is where the real damage lives.
 * Every individual freshness window can be inside its own range while the
 * three of them together describe a state no listing can ever be in.
 */
class SettingsEditor
{
    public function __construct(
        private readonly SettingsSchema $schema,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Validate and write, returning the keys whose value actually changed.
     *
     * A key posted with the value it already has is skipped rather than
     * re-written, so the audit trail does not fill with rows recording that
     * somebody opened the settings screen and pressed Save.
     *
     * @param  array<string, mixed>  $values
     * @return array<int, string>
     *
     * @throws ValidationException
     */
    public function apply(array $values, ?User $actor = null, ?string $reason = null): array
    {
        $values = $this->editableOnly($values);

        if ($values === []) {
            return [];
        }

        $this->validate($values);

        $changed = [];

        foreach ($values as $key => $value) {
            $type = $this->schema->typeOf($key);
            $encoded = $type->encode($value);

            if ($encoded === $type->encode($this->settings->get($key))) {
                continue;
            }

            $this->settings->set($key, $value, $actor, $reason);
            $changed[] = $key;
        }

        return $changed;
    }

    /**
     * Drop anything the schema does not declare as editable.
     *
     * Silently rather than with an error: the console posts the panel it was
     * showing, and a key that has since become read-only is not the operator
     * doing something wrong.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function editableOnly(array $values): array
    {
        return array_filter(
            $values,
            fn (string $key): bool => $this->schema->isEditable($key),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    private function validate(array $values): void
    {
        $keys = array_keys($values);
        $placeholders = [];
        $payload = [];
        $rules = [];
        $names = [];

        foreach ($keys as $index => $key) {
            $placeholder = 'field'.$index;
            $placeholders[$placeholder] = $key;
            $payload[$placeholder] = $values[$key];
            $names[$placeholder] = $this->schema->labelOf($key);
        }

        foreach ($this->schema->rulesFor($keys) as $key => $rule) {
            /* "freshness.reminder_days.*" carries a suffix the key does not. */
            $base = str_ends_with($key, '.*') ? substr($key, 0, -2) : $key;
            $suffix = $key === $base ? '' : '.*';

            $placeholder = array_search($base, $placeholders, true);

            if ($placeholder !== false) {
                $rules[$placeholder.$suffix] = $rule;
            }
        }

        $validator = Validator::make($payload, $rules, [], $names);

        $validator->after(fn (ValidatorInstance $validator) => $this->checkFreshnessWindows($validator, $values));

        if ($validator->fails()) {
            throw ValidationException::withMessages(
                $this->remap($validator->errors()->messages(), $placeholders),
            );
        }
    }

    /**
     * Fresh < Ageing < Hidden, or a state is unreachable.
     *
     * Read through the repository for any window the form did not post, so
     * an administrator editing one number in isolation is still checked
     * against the two stored beside it.
     *
     * @param  array<string, mixed>  $values
     */
    private function checkFreshnessWindows(ValidatorInstance $validator, array $values): void
    {
        $window = fn (string $key, int $fallback): int => (int) ($values[$key] ?? $this->settings->integer($key, $fallback));

        $fresh = $window('freshness.fresh_max_days', 3);
        $ageing = $window('freshness.ageing_max_days', 5);
        $hidden = $window('freshness.hidden_after_days', 14);

        $placeholder = fn (string $key): string => 'field'.array_search($key, array_keys($values), true);

        if (array_key_exists('freshness.ageing_max_days', $values) && $ageing <= $fresh) {
            $validator->errors()->add(
                $placeholder('freshness.ageing_max_days'),
                __('The Ageing window must be longer than the Fresh window (:days days).', ['days' => $fresh]),
            );
        }

        if (array_key_exists('freshness.hidden_after_days', $values) && $hidden <= $ageing) {
            $validator->errors()->add(
                $placeholder('freshness.hidden_after_days'),
                __('Listings must be hidden later than the Ageing window ends (:days days).', ['days' => $ageing]),
            );
        }

        if (array_key_exists('freshness.fresh_max_days', $values) && $fresh >= $ageing) {
            $validator->errors()->add(
                $placeholder('freshness.fresh_max_days'),
                __('The Fresh window must be shorter than the Ageing window (:days days).', ['days' => $ageing]),
            );
        }
    }

    /**
     * Put the errors back under the names the form posted.
     *
     * @param  array<string, array<int, string>>  $messages
     * @param  array<string, string>  $placeholders
     * @return array<string, array<int, string>>
     */
    private function remap(array $messages, array $placeholders): array
    {
        $mapped = [];

        foreach ($messages as $field => $errors) {
            $base = str_contains($field, '.') ? strstr($field, '.', true) : $field;
            $key = $placeholders[$base] ?? $base;

            $mapped['values.'.$key] = array_merge($mapped['values.'.$key] ?? [], $errors);
        }

        return $mapped;
    }
}
