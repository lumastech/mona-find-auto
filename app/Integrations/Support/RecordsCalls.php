<?php

declare(strict_types=1);

namespace App\Integrations\Support;

use Illuminate\Support\Facades\File;
use JsonException;

/**
 * Shared behaviour for the fake integrations: keep every call in memory so a
 * test can assert on it, and optionally append it to a file so a developer
 * driving the app by hand can see what would have been sent.
 */
trait RecordsCalls
{
    /** @var array<int, array{method: string, payload: array<string, mixed>, at: string}> */
    private array $calls = [];

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function recordCall(string $method, array $payload): void
    {
        $entry = [
            'method' => $method,
            'payload' => $payload,
            'at' => now()->toIso8601String(),
        ];

        $this->calls[] = $entry;

        $path = config('integrations.fakes.transcript_path');

        if (is_string($path) && $path !== '') {
            try {
                File::append($path, json_encode(['provider' => static::class, ...$entry], JSON_THROW_ON_ERROR).PHP_EOL);
            } catch (JsonException) {
                /** A transcript is a convenience; never let it break a call. */
            }
        }
    }

    /**
     * Every call made so far, oldest first.
     *
     * @return array<int, array{method: string, payload: array<string, mixed>, at: string}>
     */
    public function calls(?string $method = null): array
    {
        if ($method === null) {
            return $this->calls;
        }

        return array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['method'] === $method,
        ));
    }

    public function forgetCalls(): void
    {
        $this->calls = [];
    }
}
