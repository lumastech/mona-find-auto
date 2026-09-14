<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Support\Audit\AuditRecorder;
use App\Support\Money\Money;
use App\Support\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Model;

if (! function_exists('settings')) {
    /**
     * Read an admin-configurable platform setting, or get the repository
     * itself when called without arguments.
     *
     * settings('escrow.pickup_window_days')  → 3
     * settings()->set('escrow.pickup_window_days', 5)
     */
    function settings(?string $key = null, mixed $default = null): mixed
    {
        $repository = app(SettingsRepository::class);

        if ($key === null) {
            return $repository;
        }

        return $repository->get($key, $default);
    }
}

if (! function_exists('audit')) {
    /**
     * Append a row to the immutable audit trail.
     *
     * Pass null as the actor for anything the platform did on its own; the
     * authenticated user is used automatically when there is one.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $context
     */
    function audit(
        ?Model $actor,
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        array $context = [],
    ): AuditLog {
        return app(AuditRecorder::class)->record($actor, $action, $subject, $before, $after, $reason, $context);
    }
}

if (! function_exists('money')) {
    /**
     * Build a Money amount. Integers are read as ngwee, strings as kwacha:
     * money(1075) and money('10.75') are the same amount.
     */
    function money(Money|int|string $amount = 0): Money
    {
        return Money::from($amount);
    }
}

if (! function_exists('kwacha')) {
    /**
     * Build a Money amount from a kwacha figure: kwacha(10) is K 10.00.
     */
    function kwacha(int|string $amount): Money
    {
        return Money::ofKwacha($amount);
    }
}
