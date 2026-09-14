---
paths:
  - 'app/Support/Console/**'
---

# Console

## The staff dashboard asks modules for its numbers; it never counts
`App\Support\Console\ConsoleCounters` is a singleton registry. A module registers a `ProvidesConsoleCounters` class from its OWN service provider's bootModule(), phrases its own queue, and links to its own screen. `Admin\Http\Controllers\Admin\DashboardController` renders whatever came back and imports no other module's models.

Add a new queue tile in the module that owns the queue, not in Admin. Set the `ability` on a counter so a role that cannot act on it never sees the tile at all. One provider throwing costs one tile — the registry reports and skips.
