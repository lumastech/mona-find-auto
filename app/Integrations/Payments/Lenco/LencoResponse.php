<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Lenco;

use App\Support\Money\Money;
use Illuminate\Http\Client\Response;

/**
 * One Lenco reply, unwrapped.
 *
 * Every v2 endpoint answers in the same envelope — `{status, message, data}`
 * — and signals a refusal with `status: false` and an HTTP 400 rather than a
 * transport error. Unwrapping in one place means no caller has to remember
 * which of the two it is looking at.
 */
final readonly class LencoResponse
{
    /**
     * @param  array<string, mixed>|array<int, mixed>|null  $data
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public bool $successful,
        public string $message,
        public ?array $data,
        public array $meta,
        public array $raw,
        public int $httpStatus,
    ) {}

    public static function fromHttp(Response $response): self
    {
        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        $data = $body['data'] ?? null;

        return new self(
            successful: ($body['status'] ?? false) === true,
            message: is_string($body['message'] ?? null) ? $body['message'] : '',
            data: is_array($data) ? $data : null,
            meta: is_array($body['meta'] ?? null) ? $body['meta'] : [],
            raw: $body,
            httpStatus: $response->status(),
        );
    }

    /**
     * The payload of a single-object response.
     *
     * @return array<string, mixed>
     */
    public function object(): array
    {
        if (! $this->successful || $this->data === null || array_is_list($this->data)) {
            return [];
        }

        $object = [];

        foreach ($this->data as $key => $value) {
            $object[(string) $key] = $value;
        }

        return $object;
    }

    /**
     * The rows of a list response.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        if (! $this->successful || $this->data === null || ! array_is_list($this->data)) {
            return [];
        }

        return array_values(array_filter($this->data, 'is_array'));
    }

    /**
     * Whether a paginated listing has another page after this one.
     */
    public function hasMorePages(): bool
    {
        $current = (int) ($this->meta['currentPage'] ?? 1);
        $pages = (int) ($this->meta['pageCount'] ?? 1);

        return $current < $pages;
    }

    /**
     * Read a Lenco amount — always a decimal STRING like "20.00" — as ngwee.
     *
     * Money::ofKwacha() takes the string whole rather than casting it to a
     * float first, which is the only reason "1234.56" survives the trip
     * intact. Null and empty come back as null, not zero: a fee that has not
     * been decided yet is not a fee of nothing.
     */
    public static function money(mixed $amount): ?Money
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return Money::ofKwacha(is_string($amount) ? $amount : (string) $amount);
    }
}
