<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Enums;

/**
 * The kinds of business that sell on MonaFindAuto.
 *
 * The type is more than a label: a car breaker's stock is second-hand by
 * definition, so its listings carry the Car Breaker condition badge
 * automatically, and a garage or workshop is asked for the trade documents a
 * retailer is not.
 */
enum SellerType: string
{
    case AutoPartsSeller = 'APS';
    case SparePartsShop = 'SPS';
    case Garage = 'G';
    case CarBreaker = 'CB';
    case AutomotiveRetailer = 'AMR';
    case WorkshopOperator = 'WO';
    case CarDealer = 'CD';

    public function label(): string
    {
        return match ($this) {
            self::AutoPartsSeller => 'Auto-parts seller',
            self::SparePartsShop => 'Spare-parts shop',
            self::Garage => 'Garage',
            self::CarBreaker => 'Car breaker',
            self::AutomotiveRetailer => 'Automotive retailer',
            self::WorkshopOperator => 'Workshop operator',
            self::CarDealer => 'Car dealer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AutoPartsSeller => 'You sell new or used parts, online or from a yard.',
            self::SparePartsShop => 'You run a shop counter selling spares over the counter.',
            self::Garage => 'You repair vehicles and sell the parts you fit.',
            self::CarBreaker => 'You strip vehicles for parts. Your listings are badged Car Breaker.',
            self::AutomotiveRetailer => 'You are a retail chain or branded outlet.',
            self::WorkshopOperator => 'You run a workshop doing fitting, servicing or bodywork.',
            self::CarDealer => 'You sell vehicles and the parts that come with them.',
        };
    }

    /**
     * Whether every listing from this seller is second-hand by the nature of
     * the business. Catalog reads this to badge listings without asking.
     */
    public function sellsBreakerStock(): bool
    {
        return $this === self::CarBreaker;
    }

    /**
     * Types that work on customers' vehicles rather than only selling parts.
     * These are asked for a workshop or trade licence on top of the usual
     * registration documents.
     */
    public function performsWork(): bool
    {
        return in_array($this, [self::Garage, self::WorkshopOperator], true);
    }

    /**
     * The documents this type of business must upload before it is reviewed.
     *
     * @return array<int, DocumentType>
     */
    public function requiredDocuments(): array
    {
        $documents = [DocumentType::CertificateOfIncorporation, DocumentType::OwnerIdentification];

        if ($this->performsWork()) {
            $documents[] = DocumentType::TradeLicence;
        }

        if ($this === self::CarDealer) {
            $documents[] = DocumentType::TradeLicence;
        }

        return $documents;
    }

    /**
     * Whether the sign-up wizard asks how many bays or staff the site has.
     * Only meaningful for businesses that do work on site.
     */
    public function hasWorkshopCapacity(): bool
    {
        return $this->performsWork() || $this === self::CarBreaker;
    }

    /**
     * @return array<int, array{value: string, label: string, description: string, breaker_stock: bool}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'description' => $type->description(),
            'breaker_stock' => $type->sellsBreakerStock(),
        ], self::cases());
    }
}
