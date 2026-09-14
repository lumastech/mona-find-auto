<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Enums;

/**
 * The paperwork a seller uploads to be verified.
 *
 * Documents are private: they live on the local (non-public) disk and are
 * streamed to staff through a policy-checked route, never linked to.
 */
enum DocumentType: string
{
    case CertificateOfIncorporation = 'certificate_of_incorporation';
    case OwnerIdentification = 'owner_identification';
    case TradeLicence = 'trade_licence';
    case TaxClearance = 'tax_clearance';
    case ProofOfAddress = 'proof_of_address';

    public function label(): string
    {
        return match ($this) {
            self::CertificateOfIncorporation => 'Certificate of incorporation',
            self::OwnerIdentification => 'Owner or director ID',
            self::TradeLicence => 'Trade or workshop licence',
            self::TaxClearance => 'Tax clearance certificate',
            self::ProofOfAddress => 'Proof of business address',
        };
    }

    public function guidance(): string
    {
        return match ($this) {
            self::CertificateOfIncorporation => 'PACRA certificate showing the registration number you gave us.',
            self::OwnerIdentification => 'NRC or passport of the person who owns or runs the business.',
            self::TradeLicence => 'Council trade licence or workshop permit for the premises.',
            self::TaxClearance => 'ZRA tax clearance, if you have one. Optional.',
            self::ProofOfAddress => 'A utility bill or lease showing the address you gave us. Optional.',
        };
    }

    /**
     * The media-library collection this document is stored in.
     */
    public function collection(): string
    {
        return 'documents';
    }

    /**
     * @return array<int, array{value: string, label: string, guidance: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'guidance' => $type->guidance(),
        ], self::cases());
    }
}
