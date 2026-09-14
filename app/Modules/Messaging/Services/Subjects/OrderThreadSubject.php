<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services\Subjects;

use App\Modules\Messaging\Contracts\ThreadSubject;
use App\Modules\Messaging\Support\ThreadParties;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\Eloquent\Model;

/**
 * An order, as something to hold a conversation about.
 *
 * The only subject on which `isPaid()` is ever true, and therefore the only
 * place the screen ever steps aside. Once the money has settled, a buyer and
 * a seller arranging a collection need to be able to exchange a phone number
 * and a plot number, and redacting those would push the arrangement onto
 * WhatsApp — away from the record a dispute would be decided on.
 *
 * `paid_at` rather than the status: an order can be disputed, cancelled or
 * refunded after payment, and none of those un-pay it. What is being asked is
 * "have these two done business", and once they have, they have.
 *
 * The thread closes when the order does, so a conversation cannot be reopened
 * against a settled sale years later.
 */
class OrderThreadSubject implements ThreadSubject
{
    /**
     * @return class-string<Model>
     */
    public function handles(): string
    {
        return Order::class;
    }

    public function parties(Model $subject): ThreadParties
    {
        /** @var Order $subject */
        return ThreadParties::buyerAndSeller($subject->buyer, $subject->seller);
    }

    public function label(Model $subject): string
    {
        return $subject instanceof Order ? 'Order '.$subject->number : 'Order';
    }

    public function isPaid(Model $subject): bool
    {
        return $subject instanceof Order && $subject->paid_at !== null;
    }

    public function isClosed(Model $subject): bool
    {
        return $subject instanceof Order && $subject->closed_at !== null;
    }

    public function url(Model $subject): string
    {
        return $subject instanceof Order
            ? route('orders.show', $subject)
            : url('/');
    }
}
