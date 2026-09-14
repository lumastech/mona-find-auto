<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Support;

use App\Modules\Privacy\Contracts\PolicyDocuments;

/**
 * The fallback answer: no page is published.
 *
 * Bound by Privacy itself so the module works standalone, and overridden by
 * Admin's implementation once that module boots. A consent recorded against
 * this simply carries a null version, which is the truth.
 */
final class UnpublishedPolicyDocuments implements PolicyDocuments
{
    public function currentVersion(string $slug): ?int
    {
        return null;
    }

    public function url(string $slug): ?string
    {
        return null;
    }
}
