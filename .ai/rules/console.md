---
paths:
  - 'app/Support/Console/**'
---

# Console

## The staff dashboard asks modules for its numbers; it never counts
`App\Support\Console\ConsoleCounters` is a singleton registry. A module registers a `ProvidesConsoleCounters` class from its OWN service provider's bootModule(), phrases its own queue, and links to its own screen. `Admin\Http\Controllers\Admin\DashboardController` renders whatever came back and imports no other module's models.

Add a new queue tile in the module that owns the queue, not in Admin. Set the `ability` on a counter so a role that cannot act on it never sees the tile at all. One provider throwing costs one tile — the registry reports and skips.

## Two console registries: counters are work waiting, statistics are how it is going
`ConsoleCounters` and `ConsoleStatistics` are separate singletons with the same shape: a module registers a provider from its OWN service provider's bootModule(), and Admin's DashboardController renders whatever came back. Set `ability` on a counter/stat/chart so a role that cannot act on it never receives it. One failing provider costs its own tiles, not the screen.

Keep the split: a ConsoleCounter is a queue somebody clears this morning; a ConsoleStat is a measurement nobody clears. Mixing them turns the to-do list into a wall of numbers. Statistics are deferred (group "insights") and read over a `ConsoleWindow`, which is always Lusaka day boundaries and knows the equal-length window before it — a figure with no comparison sends `previous: null` rather than a fake baseline.

Money figures may only come from Finance (ledger sums via FinanceMetrics); other modules contribute counts. Use `DailySeries::count()` for a per-day tally — it takes a `literal-string` column and groups by the `local_date` ALIAS, which MySQL needs because the timezone offset is a bound placeholder (SQLite accepts the duplicated expression, so this class of break passes tests and 500s in the browser).
