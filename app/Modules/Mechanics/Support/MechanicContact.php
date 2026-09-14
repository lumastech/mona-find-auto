<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Support;

use App\Models\User;
use App\Modules\Mechanics\Models\MechanicProfile;
use App\Support\Contact\ContactMask;

/**
 * A mechanic's contact details as a particular viewer may see them.
 *
 * The same rule as a seller's, and deliberately the same masking: a guest
 * sees that there is a phone number and what shape it is, and not one
 * character of it. The profile page and /api/v1/mechanics/{mechanic} both
 * answer through this.
 *
 * The prompt says "message" rather than "call" because the platform would
 * rather a first contact went through Messaging, where it is on the record if
 * the job goes wrong. Whether that channel is open yet is the caller's
 * question — see MechanicProfileResource.
 */
final readonly class MechanicContact
{
    private function __construct(
        public bool $visible,
        /** @var array<int, array{key: string, label: string, value: string}> */
        public array $fields,
        public string $prompt,
    ) {}

    public static function for(MechanicProfile $profile, ?User $viewer): self
    {
        $visible = $viewer !== null;

        return new self(
            visible: $visible,
            fields: [
                ContactMask::field('phone', 'Phone', $profile->phone, $visible, ContactMask::phone(...)),
                ContactMask::field('email', 'Email', $profile->email, $visible, ContactMask::email(...)),
                ContactMask::field('contact_person', 'Contact person', $profile->display_name, $visible, ContactMask::name(...)),
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
