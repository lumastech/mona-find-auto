<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Seller;

use App\Modules\Inventory\Models\StockImportBatch;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A bulk stock file arriving from the seller portal.
 *
 * The mime list is wider than the extension list because browsers disagree
 * about what an XLSX is — Chrome on Android has been known to call one
 * application/octet-stream. The extension is what the reader actually
 * dispatches on, and the reader fails loudly on a file that is not what its
 * name claims.
 */
class StockImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', StockImportBatch::class) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,application/octet-stream,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'extensions:csv,xlsx',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.extensions' => 'Upload the CSV or XLSX template. Other spreadsheet formats cannot be read.',
            'file.max' => 'That file is larger than 10 MB. Split it and upload the parts separately.',
        ];
    }
}
