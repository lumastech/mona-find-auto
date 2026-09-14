<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\StockImportBatch;
use App\Support\Roles\Role;

/**
 * A bulk upload belongs to the shop that made it.
 *
 * There is no staff path here on purpose: a stock file is a seller's own
 * price list, and MonaFind has no reason to read one. Staff who need to
 * correct a quantity do it through the ledger, where it is audited.
 */
class StockImportBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(Role::sellerPortal()) && $user->ownsSeller();
    }

    public function view(User $user, StockImportBatch $batch): bool
    {
        return $this->owns($user, $batch);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Write the batch to the shelves.
     */
    public function apply(User $user, StockImportBatch $batch): bool
    {
        return $this->owns($user, $batch) && $batch->status->isApplicable();
    }

    public function discard(User $user, StockImportBatch $batch): bool
    {
        return $this->owns($user, $batch);
    }

    private function owns(User $user, StockImportBatch $batch): bool
    {
        return $user->seller !== null && $user->seller->getKey() === $batch->seller_id;
    }
}
