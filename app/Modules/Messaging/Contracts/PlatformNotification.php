<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Contracts;

use App\Modules\Messaging\Enums\NotificationEvent;

/**
 * A notification that goes through the platform's routing rules.
 *
 * Implemented by every notification in every module, and it asks for exactly
 * one thing: which entry in the catalogue this is. From that, Messaging works
 * out the channels — the admin matrix, then the recipient's preferences, then
 * whether the recipient can actually be reached that way.
 *
 * A notification that does NOT implement this keeps whatever `via()` it
 * defines and is routed by Laravel alone. That is the escape hatch for
 * anything framework-owned (Fortify's password reset, for instance) which has
 * no business in a buyer's preference screen.
 *
 * Consumer-owned contract in the same sense as Search's
 * SellerReputationProvider: the module that needs the behaviour declares the
 * interface, and the modules that have the news implement it.
 */
interface PlatformNotification
{
    /**
     * Which catalogue entry this notification is.
     */
    public function notificationEvent(): NotificationEvent;
}
