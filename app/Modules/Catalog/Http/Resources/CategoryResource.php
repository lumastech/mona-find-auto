<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One node of the category tree.
 *
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'depth' => $this->depth,
            'position' => $this->position,
            'is_active' => $this->is_active,
            /* A root is a heading buyers browse; listings hang off the levels below it. */
            'selectable' => $this->depth > 0,
            'children' => self::collection($this->whenLoaded('children'))->resolve($request),
        ];
    }
}
