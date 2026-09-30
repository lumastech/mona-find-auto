<?php

declare(strict_types=1);

namespace App\Modules\Search\Support;

/**
 * One listing document, reshaped into a `search_listings` row.
 *
 * The document is the same array Meilisearch is sent, so both drivers index
 * exactly the same facts about a listing — the SQL driver only changes how
 * they are stored. The text columns are normalised with SearchTokens, which
 * is also how the query side splits what the buyer typed; the two can never
 * disagree about what counts as a word.
 */
final class SqlListingRow
{
    public const TABLE = 'search_listings';

    /** Coordinates are stored in millionths of a degree — about 11cm. */
    public const MICRODEGREES = 1_000_000;

    /**
     * @param  array<string, mixed>  $document  Services\ProductDocument::for()
     * @return array<string, mixed>
     */
    public static function from(array $document, string $matchText): array
    {
        $searchable = array_map(
            static fn (string $attribute): string => (string) ($document[$attribute] ?? ''),
            ProductIndex::searchableAttributes(),
        );

        /** @var array<int, int> $years */
        $years = $document['years'];

        /** @var array<int, int> $categoryIds */
        $categoryIds = $document['category_ids'];

        /** @var array{lat: float, lng: float}|null $geo */
        $geo = $document['_geo'] ?? null;

        return [
            'product_id' => $document['id'],
            'search_text' => self::text(implode(' ', $searchable)),
            'match_text' => self::text($matchText),
            'category_id' => $document['category_id'],
            'category_path' => '/'.implode('/', $categoryIds).'/',
            'make_id' => $document['make_id'],
            'vehicle_model_id' => $document['vehicle_model_id'],
            'year_from' => $years === [] ? null : min($years),
            'year_to' => $years === [] ? null : max($years),
            'condition' => $document['condition'],
            'inspected' => $document['inspected'],
            'sourcing' => $document['sourcing'],
            'price_ngwee' => $document['price_ngwee'],
            'in_stock' => $document['in_stock'],
            'delivery_available' => $document['delivery_available'],
            'seller_id' => $document['seller_id'],
            'seller_type' => $document['seller_type'],
            'seller_verified' => $document['seller_verified'],
            'province_id' => $document['province_id'],
            'city_id' => $document['city_id'],
            'quality_hundredths' => (int) round((float) $document['quality_score'] * 100),
            'published_timestamp' => $document['published_at'],
            'latitude_e6' => $geo === null ? null : self::microdegrees($geo['lat']),
            'longitude_e6' => $geo === null ? null : self::microdegrees($geo['lng']),
        ];
    }

    /**
     * " toyota hilux brake pads " — every word padded by a space, so a LIKE
     * can insist on a whole word.
     */
    public static function text(string $text): string
    {
        return ' '.implode(' ', SearchTokens::of($text)->tokens).' ';
    }

    public static function microdegrees(float $degrees): int
    {
        return (int) round($degrees * self::MICRODEGREES);
    }
}
