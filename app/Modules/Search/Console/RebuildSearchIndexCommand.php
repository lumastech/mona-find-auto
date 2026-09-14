<?php

declare(strict_types=1);

namespace App\Modules\Search\Console;

use App\Modules\Search\Services\ListingIndexer;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `php artisan search:reindex` — rebuild the listings index by hand.
 *
 * The same work the nightly job does, run in the foreground with a progress
 * bar. This is what you reach for after changing the ranking weights, after
 * restoring a database, or after Meilisearch has been down long enough that
 * nobody trusts what is in it.
 *
 * `--settings` pushes the index configuration first. Ranking rules and
 * filterable attributes have to be in place before the documents arrive, or
 * the first search after a rebuild filters on attributes Meilisearch has not
 * been told about and quietly returns the wrong thing.
 */
#[AsCommand(name: 'search:reindex')]
class RebuildSearchIndexCommand extends Command
{
    protected $signature = 'search:reindex
                            {--settings : Sync the index settings before importing}';

    protected $description = 'Rebuild the Meilisearch listings index from the database';

    public function handle(ListingIndexer $indexer): int
    {
        if ($this->option('settings') && $this->call('scout:sync-index-settings') !== self::SUCCESS) {
            $this->components->error('Could not sync the index settings. Nothing was imported.');

            return self::FAILURE;
        }

        $this->components->info('Rebuilding the listings index.');

        $bar = $this->output->createProgressBar();
        $bar->start();

        $counts = $indexer->rebuild(static fn (int $processed) => $bar->advance($processed));

        $bar->finish();
        $this->newLine(2);

        $this->components->info(sprintf(
            '%d listing(s) indexed, %d removed.',
            $counts['indexed'],
            $counts['removed'],
        ));

        return self::SUCCESS;
    }
}
