<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests\Seller;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ListingFieldRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Replacing a listing's walk-round video.
 */
class ListingVideoRequest extends FormRequest
{
    use ListingFieldRules;

    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product && $this->user()?->can('update', $product) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->videoRules();
    }

    public function video(): UploadedFile
    {
        /** @var UploadedFile $video */
        $video = $this->file('video');

        return $video;
    }
}
