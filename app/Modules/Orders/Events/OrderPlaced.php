<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

use App\Modules\Orders\Models\OrderGroup;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A cart has become orders, and the buyer is being asked to pay.
 *
 * Nothing has been promised to anybody yet — no stock has moved and no seller
 * has been told. This exists so that Payments can start a collection off the
 * back of it and so abandoned-checkout work has something to hang on, not as
 * a signal that a sale happened. OrderPaid is that signal.
 */
class OrderPlaced
{
    use Dispatchable, SerializesModels;

    public function __construct(public OrderGroup $group) {}
}
