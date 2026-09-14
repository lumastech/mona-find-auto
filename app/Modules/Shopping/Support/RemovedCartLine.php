<?php

declare(strict_types=1);

namespace App\Modules\Shopping\Support;

use App\Modules\Shopping\Enums\CartLineIssue;

/**
 * A line that was in the cart and is not any more.
 *
 * The name is kept because the row is not. A cart that silently shrinks
 * between two visits reads as a bug, and a buyer who cannot see which part
 * left has no way to go and look for another one — so what was removed
 * outlives the removal, at least until the page is rendered.
 */
final readonly class RemovedCartLine
{
    public function __construct(
        public int $productId,
        public string $productName,
        public string $productSlug,
        public string $sellerName,
        public CartLineIssue $reason = CartLineIssue::Unavailable,
    ) {}

    /**
     * @return array{product_id: int, name: string, slug: string, seller: string, reason: string, reason_label: string}
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'name' => $this->productName,
            'slug' => $this->productSlug,
            'seller' => $this->sellerName,
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
        ];
    }
}
