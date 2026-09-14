<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Modules\Catalog\Models\Category;
use RuntimeException;

/**
 * A category move the tree cannot represent.
 */
class InvalidCategoryPlacement extends RuntimeException
{
    public static function tooDeep(int $maximumDepth): self
    {
        return new self(sprintf(
            'The category tree is %d levels deep; this move would go deeper.',
            $maximumDepth + 1,
        ));
    }

    public static function intoOwnSubtree(Category $category): self
    {
        return new self(sprintf('"%s" cannot be moved inside itself.', $category->name));
    }
}
