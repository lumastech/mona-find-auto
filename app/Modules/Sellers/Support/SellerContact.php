<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Support;

use App\Models\User;
use App\Modules\Sellers\Models\Seller;
use App\Support\Contact\ContactMask;

/**
 * A seller's contact details as a particular viewer may see them.
 *
 * Guests get the field *labels* and a masked shape — enough to see that a
 * phone number exists and roughly what it looks like — but never a character
 * of the real value. The masking happens on the server, in ContactMask,
 * because a blurred CSS filter over the real number is not privacy: it is in
 * the response, and anybody can read it out of the page source.
 *
 * The storefront page and /api/v1/sellers/{id} both answer through this, so
 * there is one rule rather than two that can drift apart. Mechanics have
 * their own entry point over the same masking — see MechanicContact.
 */
final readonly class SellerContact
{
    private function __construct(
        public bool $visible,
        /** @var array<int, array{key: string, label: string, value: string}> */
        public array $fields,
        public string $prompt,
    ) {}

    /**
     * Build the contact block for whoever is asking. A null viewer is a guest.
     */
    public static function for(Seller $seller, ?User $viewer): self
    {
        $visible = $viewer !== null;

        return new self(
            visible: $visible,
            fields: [
                ContactMask::field('phone', 'Phone', $seller->phone, $visible, ContactMask::phone(...)),
                ContactMask::field('email', 'Email', $seller->email, $visible, ContactMask::email(...)),
                ContactMask::field('contact_person', 'Contact person', $seller->contact_person, $visible, ContactMask::name(...)),
            ],
            prompt: $visible ? '' : 'Log in to view',
        );
    }

    /**
     * @return array{visible: bool, prompt: string, fields: array<int, array{key: string, label: string, value: string}>}
     */
    public function toArray(): array
    {
        return [
            'visible' => $this->visible,
            'prompt' => $this->prompt,
            'fields' => $this->fields,
        ];
    }
}
