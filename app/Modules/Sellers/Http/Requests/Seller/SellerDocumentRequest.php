<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Requests\Seller;

use App\Modules\Sellers\Enums\DocumentType;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Support\SellerFieldRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Stringable;

/**
 * Uploading one verification document.
 */
class SellerDocumentRequest extends FormRequest
{
    use SellerFieldRules;

    public function authorize(): bool
    {
        $seller = $this->seller();

        return $seller !== null && $this->user()?->can('manage', $seller) === true;
    }

    /**
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    public function rules(): array
    {
        return $this->documentStepRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimes' => 'Upload a PDF or a photo (JPG, PNG or WebP).',
            'file.max' => 'That file is larger than 10MB. Photograph the page rather than scanning it at full resolution.',
        ];
    }

    public function documentType(): DocumentType
    {
        return DocumentType::from($this->string('document_type')->toString());
    }

    public function document(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }

    public function seller(): ?Seller
    {
        return $this->user()?->seller;
    }
}
