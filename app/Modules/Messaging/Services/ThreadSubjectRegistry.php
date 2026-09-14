<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Contracts\ThreadSubject;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Which resolver speaks for which kind of conversable thing.
 *
 * Messaging ships three — listings, quote requests and orders — registered
 * from its own provider. A fourth is a class and a register() call; no
 * interface here names an order and no branch here tests for one.
 */
class ThreadSubjectRegistry
{
    /** @var array<class-string<Model>, ThreadSubject> */
    private array $resolvers = [];

    public function register(ThreadSubject $resolver): void
    {
        $this->resolvers[$resolver->handles()] = $resolver;
    }

    /**
     * The resolver for a model, or null when nothing has claimed its type.
     */
    public function find(Model $subject): ?ThreadSubject
    {
        return $this->resolvers[$subject::class] ?? null;
    }

    /**
     * The resolver for a model, or an exception — for the paths where a
     * missing resolver is a wiring bug rather than a user's mistake.
     */
    public function for(Model $subject): ThreadSubject
    {
        $resolver = $this->find($subject);

        if ($resolver === null) {
            throw new RuntimeException(sprintf('Nothing knows how to hold a conversation about a %s.', $subject::class));
        }

        return $resolver;
    }

    /**
     * @return array<int, ThreadSubject>
     */
    public function all(): array
    {
        return array_values($this->resolvers);
    }
}
