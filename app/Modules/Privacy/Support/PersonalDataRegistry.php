<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Support;

use App\Modules\Privacy\Contracts\PersonalDataSource;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Where every module declares the personal data it holds.
 *
 * A singleton that modules write to from their service providers, in the same
 * shape as `ConsoleCounters` and `ReferenceRegistry`:
 *
 *     $this->app->make(PersonalDataRegistry::class)->register(OrderPersonalData::class);
 *
 * Sources are stored as class names and resolved on use. A registry that held
 * instances would build ten services on every request, including the many
 * requests that are not an export.
 *
 * ## Order matters, a little
 *
 * Export sections appear in registration order, so the module list in
 * config/modules.php decides the order of the file — identity first, then the
 * things that happened to that identity. Erasure runs in the same order,
 * which is deliberate: Identity's source anonymises the account row last of
 * all its work, so a source that fails part-way leaves an account that is
 * still recognisably mid-erasure rather than one that is unidentifiable but
 * still holding data elsewhere.
 */
class PersonalDataRegistry
{
    /** @var array<int, class-string<PersonalDataSource>> */
    private array $sources = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Register a source.
     *
     * The parameter is a bare `class-string` rather than
     * `class-string<PersonalDataSource>` on purpose: modules call this from
     * their service providers with a literal, and the runtime check below is
     * what turns a typo or a class that forgot the interface into a readable
     * exception at boot rather than a fatal later. Narrowing the docblock
     * would make that check dead code in static analysis and alive in
     * production, which is the wrong way round.
     *
     * @param  class-string  $source
     */
    public function register(string $source): void
    {
        if (! is_a($source, PersonalDataSource::class, true)) {
            throw new InvalidArgumentException(
                sprintf('[%s] must implement %s to be registered as a personal data source.', $source, PersonalDataSource::class),
            );
        }

        if (! in_array($source, $this->sources, true)) {
            $this->sources[] = $source;
        }
    }

    /**
     * Every registered source, resolved, in registration order.
     *
     * @return array<int, PersonalDataSource>
     */
    public function all(): array
    {
        return array_map(
            fn (string $source): PersonalDataSource => $this->container->make($source),
            $this->sources,
        );
    }

    /**
     * @return array<int, class-string<PersonalDataSource>>
     */
    public function registered(): array
    {
        return $this->sources;
    }
}
