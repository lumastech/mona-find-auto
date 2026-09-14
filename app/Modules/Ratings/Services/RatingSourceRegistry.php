<?php

declare(strict_types=1);

namespace App\Modules\Ratings\Services;

use App\Modules\Ratings\Contracts\RatingSourceResolver;
use App\Modules\Ratings\Enums\RatingSource;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Which resolver speaks for which kind of rateable thing.
 *
 * Ratings ships one entry — completed orders — and the Mechanics module adds
 * endorsements by calling register() from its own service provider. That is
 * the whole extension mechanism: no interface in this module names an
 * endorsement, and no branch in this module tests for one.
 */
class RatingSourceRegistry
{
    /** @var array<class-string<Model>, RatingSourceResolver> */
    private array $resolvers = [];

    public function register(RatingSourceResolver $resolver): void
    {
        $this->resolvers[$resolver->handles()] = $resolver;
    }

    /**
     * The resolver for a model, or null when nothing has claimed its type.
     */
    public function find(Model $source): ?RatingSourceResolver
    {
        return $this->resolvers[$source::class] ?? null;
    }

    /**
     * The resolver for a model, or an exception — for the paths where a
     * missing resolver is a wiring bug rather than a user's mistake.
     */
    public function for(Model $source): RatingSourceResolver
    {
        $resolver = $this->find($source);

        if ($resolver === null) {
            throw new RuntimeException(sprintf('Nothing knows how to rate a %s.', $source::class));
        }

        return $resolver;
    }

    public function sourceFor(Model $source): RatingSource
    {
        return $this->for($source)->source();
    }

    /**
     * @return array<int, RatingSourceResolver>
     */
    public function all(): array
    {
        return array_values($this->resolvers);
    }
}
