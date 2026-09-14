<?php

declare(strict_types=1);

namespace App\Modules\Identity\Policies;

use App\Models\User;
use App\Modules\Identity\Models\UserAddress;

/**
 * A delivery address belongs to exactly one buyer, and nobody else — staff
 * included — reads or edits it through these screens.
 */
class UserAddressPolicy
{
    public function view(User $user, UserAddress $address): bool
    {
        return $this->owns($user, $address);
    }

    public function update(User $user, UserAddress $address): bool
    {
        return $this->owns($user, $address);
    }

    public function delete(User $user, UserAddress $address): bool
    {
        return $this->owns($user, $address);
    }

    private function owns(User $user, UserAddress $address): bool
    {
        return $user->getKey() === $address->user_id;
    }
}
