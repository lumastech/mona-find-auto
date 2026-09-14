<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Concerns;

use App\Modules\Sellers\Models\Seller;
use App\Modules\Sellers\Models\SellerRegistrationDraft;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The selling half of App\Models\User.
 *
 * An account may run one business. The seller role decides whether the portal
 * opens; this decides which shop it opens onto.
 *
 * @phpstan-require-extends Model
 */
trait SellsOnMonaFind
{
    /**
     * @return HasOne<Seller, $this>
     */
    public function seller(): HasOne
    {
        return $this->hasOne(Seller::class);
    }

    /**
     * The sign-up in progress, if this account started one.
     *
     * @return HasOne<SellerRegistrationDraft, $this>
     */
    public function sellerRegistrationDraft(): HasOne
    {
        return $this->hasOne(SellerRegistrationDraft::class);
    }

    /**
     * Whether this account runs a business here at all — regardless of
     * whether that business has been verified yet.
     */
    public function ownsSeller(): bool
    {
        return $this->seller()->exists();
    }
}
