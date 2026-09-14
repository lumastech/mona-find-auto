<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * The verdict on one dependency.
 *
 * `critical` is what separates a page and a deployment gate from a dashboard.
 * Meilisearch being down degrades search to nothing, which is bad; the
 * database being down means the platform cannot take an order, which is
 * different. Only a failing critical check makes the endpoint answer 503, and
 * only a 503 should take an instance out of a load balancer.
 */
final readonly class HealthCheck
{
    private function __construct(
        public string $name,
        public bool $healthy,
        public bool $critical,
        public ?string $message = null,
        public ?float $durationMs = null,
        /** @var array<string, scalar|null> */
        public array $context = [],
    ) {}

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function passing(string $name, bool $critical, ?float $durationMs = null, array $context = []): self
    {
        return new self($name, true, $critical, null, $durationMs, $context);
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function failing(string $name, bool $critical, string $message, ?float $durationMs = null, array $context = []): self
    {
        return new self($name, false, $critical, $message, $durationMs, $context);
    }

    /**
     * Not configured for this deployment, which is not a failure.
     *
     * A local install with no Meilisearch should not report itself unhealthy;
     * it should report that it is not using Meilisearch.
     */
    public static function skipped(string $name, string $message): self
    {
        return new self($name, true, false, $message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->healthy ? 'ok' : 'failing',
            'critical' => $this->critical,
            'message' => $this->message,
            'duration_ms' => $this->durationMs,
            ...$this->context,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
