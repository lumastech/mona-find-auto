<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Services;

use App\Models\User;
use App\Modules\Privacy\Contracts\ErasureBlocker;
use Illuminate\Contracts\Container\Container;

/**
 * Asks every module whether this account can be erased yet.
 *
 * Erasing somebody in the middle of an order is not a privacy win, it is a
 * support incident: the seller is still holding a part, the money is still in
 * escrow, and the buyer no longer exists to confirm receipt. The Act allows
 * processing to continue where it is necessary to perform a contract, which
 * is exactly what an order in flight is.
 *
 * So modules that can be part-way through something with a person register an
 * `ErasureBlocker`. Each returns a sentence explaining the hold, or null. The
 * first sentence wins and becomes the reason on the request — a person who is
 * told "you have an order awaiting collection" can do something about it, in
 * a way that "blocked" does not allow.
 *
 * Registration mirrors PersonalDataRegistry: class names in, resolved on use.
 */
class ErasureGuard
{
    /** @var array<int, class-string<ErasureBlocker>> */
    private array $blockers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<ErasureBlocker>  $blocker
     */
    public function register(string $blocker): void
    {
        if (! in_array($blocker, $this->blockers, true)) {
            $this->blockers[] = $blocker;
        }
    }

    /**
     * The first reason this account cannot be erased, or null when nothing
     * stands in the way.
     */
    public function blockerFor(User $user): ?string
    {
        foreach ($this->blockers as $class) {
            /** @var ErasureBlocker $blocker */
            $blocker = $this->container->make($class);

            $reason = $blocker->blocksErasureOf($user);

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Every reason at once, for the settings screen — a person deciding
     * whether to wait should see all of them, not the first one.
     *
     * @return array<int, string>
     */
    public function blockersFor(User $user): array
    {
        $reasons = [];

        foreach ($this->blockers as $class) {
            /** @var ErasureBlocker $blocker */
            $blocker = $this->container->make($class);

            $reason = $blocker->blocksErasureOf($user);

            if ($reason !== null) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }
}
