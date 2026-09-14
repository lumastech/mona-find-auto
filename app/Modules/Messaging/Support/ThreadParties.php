<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use App\Models\User;
use App\Modules\Messaging\Enums\ThreadRole;
use App\Modules\Sellers\Models\Seller;

/**
 * Who belongs in a conversation about one subject.
 *
 * Carried as an object rather than a pair of arguments because the seller and
 * the seller's user are two different things and it is easy to pass the wrong
 * one: the thread is with the SHOP (which is what `seller` records, for the
 * inbox), but the notification and the participant row belong to a PERSON.
 */
final readonly class ThreadParties
{
    /**
     * @param  array<int, array{user: User, role: ThreadRole}>  $participants
     */
    public function __construct(
        public array $participants,
        public ?Seller $seller = null,
    ) {}

    /**
     * A buyer talking to a shop — by far the commonest case.
     */
    public static function buyerAndSeller(User $buyer, Seller $seller): self
    {
        return new self(
            participants: [
                ['user' => $buyer, 'role' => ThreadRole::Buyer],
                ['user' => $seller->user, 'role' => ThreadRole::Seller],
            ],
            seller: $seller,
        );
    }

    /**
     * The user ids in the conversation, sorted — the half of the dedupe key
     * that is not the subject.
     *
     * @return array<int, int>
     */
    public function userIds(): array
    {
        $ids = array_map(
            static fn (array $participant): int => (int) $participant['user']->getKey(),
            $this->participants,
        );

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
