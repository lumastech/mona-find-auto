<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Enums;

/**
 * The things a person is asked to agree to, and whether they may say no.
 *
 * The Zambia Data Protection Act 2021 distinguishes processing that is
 * necessary to provide the service from processing that needs freely given
 * consent. Bundling the two — one tickbox covering the terms and the
 * marketing — is what makes consent not freely given, so they are separate
 * records here and separate checkboxes on the form.
 */
enum ConsentType: string
{
    /**
     * The platform's terms of service. Required: there is no version of
     * MonaFind a person can use without them.
     */
    case Terms = 'terms';

    /**
     * Acknowledgement of the privacy notice — how MonaFind processes personal
     * data. Required, and recorded separately from the terms so that a change
     * to one does not silently re-consent the other.
     */
    case Privacy = 'privacy';

    /**
     * Marketing by SMS and email. Optional, and off unless chosen. This is
     * the one that must be genuinely refusable.
     */
    case Marketing = 'marketing';

    public function label(): string
    {
        return match ($this) {
            self::Terms => 'Terms of service',
            self::Privacy => 'Privacy notice',
            self::Marketing => 'Marketing messages',
        };
    }

    /**
     * Can the account exist without this consent?
     */
    public function isRequired(): bool
    {
        return match ($this) {
            self::Terms, self::Privacy => true,
            self::Marketing => false,
        };
    }

    /**
     * May a person take this consent back later?
     *
     * Withdrawing the terms or the privacy notice is not a consent
     * withdrawal, it is a request to close the account — so those are not
     * withdrawable here, and the settings screen offers deletion instead.
     */
    public function isWithdrawable(): bool
    {
        return ! $this->isRequired();
    }

    /**
     * The content page whose current version the consent is recorded against,
     * or null where there is no published document.
     *
     * The slugs are literals rather than a reference to Admin's ContentPage
     * constant: Privacy resolves versions through the PolicyDocuments
     * contract precisely so that it does not depend on the module that
     * publishes them. "platform-terms" is the slug ContentPageSeeder uses.
     */
    public function contentPageSlug(): ?string
    {
        return match ($this) {
            self::Terms => 'platform-terms',
            self::Privacy => 'privacy',
            self::Marketing => null,
        };
    }

    /**
     * @return array<int, self>
     */
    public static function requiredAtRegistration(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $type): bool => $type->isRequired()));
    }
}
