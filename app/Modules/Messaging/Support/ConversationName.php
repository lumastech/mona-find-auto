<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use App\Models\User;

/**
 * How somebody is named in a conversation.
 *
 * The SHOP's name when they have one, rather than the counter hand's: a buyer
 * dealt with a business, and "Mwansa Auto Spares replied" is what they will
 * recognise on a notification three days later.
 */
final class ConversationName
{
    public static function for(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $seller = $user->seller;

        return $seller === null ? $user->name : $seller->business_name;
    }

    /**
     * The same answer, with a fallback for the messages the platform wrote
     * itself.
     */
    public static function forOrPlatform(?User $user): string
    {
        return self::for($user) ?? 'MonaFind';
    }
}
