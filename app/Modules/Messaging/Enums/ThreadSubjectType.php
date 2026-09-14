<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Enums;

use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Modules\Sellers\Models\Seller;
use App\Modules\Shopping\Models\Quotation;
use Illuminate\Database\Eloquent\Model;

/**
 * What a request is allowed to say a conversation is about.
 *
 * A closed list, and the reason is the request: "open a thread about
 * {subject_type}/{id}" arrives from a browser, and a parameter that carries a
 * model class name is a parameter somebody will eventually point at a class
 * nobody meant to expose. So the wire format is one of these four words, and
 * the mapping to a class lives here rather than in a controller.
 */
enum ThreadSubjectType: string
{
    case Listing = 'listing';
    case Shop = 'shop';
    case Quotation = 'quotation';
    case Order = 'order';

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Listing => Product::class,
            self::Shop => Seller::class,
            self::Quotation => Quotation::class,
            self::Order => Order::class,
        };
    }

    /**
     * Whether the parties are known from the subject alone.
     *
     * False for a listing and a shop: both belong to a seller but to no
     * particular buyer, so whoever is asking has to be supplied. True for a
     * quote and an order, which name both sides on the row.
     */
    public function knowsItsParties(): bool
    {
        return match ($this) {
            self::Quotation, self::Order => true,
            self::Listing, self::Shop => false,
        };
    }

    public function find(int $id): ?Model
    {
        $class = $this->modelClass();

        return $class::query()->find($id);
    }
}
