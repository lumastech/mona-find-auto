<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Support;

use Illuminate\Support\Carbon;

/**
 * What the storefront gets back when a buyer contacts a seller.
 *
 * Deliberately not a model. The thing on the other side of
 * SellerEnquiryChannel is a Shopping row today and will be a Messaging thread
 * later, and the page that renders it should not have to know which — it
 * needs an id to link to, the text that was sent, and when.
 */
final readonly class EnquiryReference
{
    public function __construct(
        public string $id,
        public string $message,
        public Carbon $sentAt,
        public ?string $threadUrl = null,
    ) {}

    /**
     * @return array{id: string, message: string, sent_at: string, thread_url: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'message' => $this->message,
            'sent_at' => $this->sentAt->toIso8601String(),
            'thread_url' => $this->threadUrl,
        ];
    }
}
