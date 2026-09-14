<?php

declare(strict_types=1);

namespace App\Modules\Search\Http\Requests;

use App\Modules\Catalog\Enums\Condition;
use App\Modules\Catalog\Enums\PartSourcing;
use App\Modules\Search\Enums\SearchSort;
use App\Modules\Search\Support\SearchCriteria;
use App\Modules\Sellers\Enums\SellerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The one definition of what a search request may contain.
 *
 * The storefront page and /api/v1/search take exactly the same parameters,
 * which is not a coincidence to be maintained by hand: the mobile app has to
 * be able to reproduce any result a buyer can reach in the browser, including
 * the link they were sent.
 *
 * Everything is optional. A bare /search is a valid request — it is what the
 * header's search box submits before anybody types.
 */
class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        /* Guests browse everything, search included. */
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],

            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'make_id' => ['nullable', 'integer', 'exists:makes,id'],
            'vehicle_model_id' => ['nullable', 'integer', 'exists:vehicle_models,id'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:'.(now()->year + 2)],

            'condition' => ['nullable', Rule::enum(Condition::class)],
            'inspected' => ['nullable', 'boolean'],
            'sourcing' => ['nullable', Rule::enum(PartSourcing::class)],

            /* Integer ngwee, like every other money value that crosses the wire. */
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0', 'gte:min_price'],

            'seller_type' => ['nullable', Rule::enum(SellerType::class)],
            'verified' => ['nullable', 'boolean'],
            'delivery' => ['nullable', 'boolean'],
            'in_stock' => ['nullable', 'boolean'],

            'province_id' => ['nullable', 'integer', 'exists:provinces,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],

            /* A half-given location is a bug on the caller's side, not a default. */
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius_km' => ['nullable', 'integer', 'min:1', 'max:'.SearchCriteria::MAX_RADIUS_KM],

            'sort' => ['nullable', Rule::in(SearchSort::values())],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.SearchCriteria::MAX_PER_PAGE],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lat.required_with' => 'A location needs both a latitude and a longitude.',
            'lng.required_with' => 'A location needs both a latitude and a longitude.',
            'max_price.gte' => 'The highest price must be at least the lowest price.',
        ];
    }

    public function criteria(): SearchCriteria
    {
        return SearchCriteria::fromArray($this->validated());
    }
}
