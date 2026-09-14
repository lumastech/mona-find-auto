<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

/**
 * MonaFind's own half of what a buyer accepts at checkout.
 *
 * Three things travel together here because they are shown together and
 * recorded together: the platform's terms of use, the minimum refund window,
 * and the sentence that states it in words a buyer will actually read.
 *
 * The minimum refund rule is the reason this is not just a settings lookup at
 * the point of use. It is a promise that overrides whatever a seller wrote in
 * their own refund policy, it sits beside that policy on screen so nobody can
 * be talked out of it by the seller's wording, and its exact text is copied
 * onto the acceptance record — because settings have no version history, and
 * an administrator editing the sentence must not silently restate what past
 * buyers were promised.
 */
final readonly class PlatformTerms
{
    public function __construct(
        public string $version,
        public string $body,
        public int $minimumRefundDays,
        public string $minimumRefundStatement,
    ) {}

    public static function current(): self
    {
        return new self(
            version: (string) settings('policies.platform_terms_version', '1'),
            body: (string) settings('policies.platform_terms_body', ''),
            minimumRefundDays: (int) settings('policies.minimum_refund_days', 3),
            minimumRefundStatement: (string) settings('policies.minimum_refund_statement', ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'body' => $this->body,
            'minimum_refund_days' => $this->minimumRefundDays,
            'minimum_refund_statement' => $this->minimumRefundStatement,
        ];
    }
}
