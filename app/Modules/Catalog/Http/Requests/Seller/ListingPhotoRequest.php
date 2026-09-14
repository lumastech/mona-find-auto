<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests\Seller;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ListingFieldRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Adding photos to an existing listing.
 */
class ListingPhotoRequest extends FormRequest
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
        return $this->photoRules(required: true);
    }

    /**
     * @return array<int, UploadedFile>
     */
    public function photos(): array
    {
        /** @var array<int, UploadedFile> $photos */
        $photos = $this->file('photos', []);

        return array_values($photos);
    }
}
