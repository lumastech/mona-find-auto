<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

/**
 * What a set of figures is broken down by.
 *
 * All three hang off the ORDER an entry references, because that is the only
 * thing on a journal entry that knows anything about the trade. Revenue
 * accounts are platform-wide and carry no subject of their own, so without
 * `journal_entries.reference` pointing at the order there would be no honest
 * way to say which province a commission came from.
 *
 * Seller type and province are exact: an order has exactly one seller, who
 * has one type and sits in one province, so grouping loses nothing. Category
 * is not — see FinanceMetrics::byCategory() for how an order spanning three
 * categories is split without losing a ngwee.
 */
enum MetricDimension: string
{
    case SellerType = 'seller_type';

    case Category = 'category';

    case Province = 'province';

    public function label(): string
    {
        return match ($this) {
            self::SellerType => 'Seller type',
            self::Category => 'Category',
            self::Province => 'Province',
        };
    }

    /**
     * Whether figures in this breakdown are apportioned rather than grouped.
     *
     * Surfaced on the screen: a reader is entitled to know that a category
     * row is a share of orders rather than a count of them.
     */
    public function isApportioned(): bool
    {
        return $this === self::Category;
    }

    /**
     * @return array<int, array{value: string, label: string, apportioned: bool}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'apportioned' => $case->isApportioned(),
        ], self::cases());
    }
}
