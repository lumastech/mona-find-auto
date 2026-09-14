<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * The OAuth providers a person may sign in with.
 *
 * A provider is only offered once its credentials are configured, so a
 * deployment without Facebook keys simply does not show a Facebook button
 * rather than showing one that fails.
 */
enum SocialProvider: string
{
    case Google = 'google';
    case Facebook = 'facebook';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Facebook => 'Facebook',
        };
    }

    public function isConfigured(): bool
    {
        return filled(config("services.{$this->value}.client_id"))
            && filled(config("services.{$this->value}.client_secret"));
    }

    /**
     * The providers this deployment can actually use.
     *
     * @return array<int, self>
     */
    public static function configured(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $provider): bool => $provider->isConfigured(),
        ));
    }
}
