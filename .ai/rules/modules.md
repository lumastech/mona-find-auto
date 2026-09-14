---
paths:
  - 'app/Modules/**'
---

# Modules

## Adding a module: provider, config entry, route files
A module is `app/Modules/{Name}` with `{Name}ServiceProvider extends App\Support\Modules\ModuleServiceProvider`, listed in `config/modules.php`. `App\Providers\ModulesServiceProvider` registers them in that order.

- `register()`/`boot()` are final on the base class — override `registerModule()` / `bootModule()`.
- `routes/{web,seller,admin,api}.php` are loaded automatically with the middleware, URI prefix and name prefix from `config/modules.php` route_groups. Declare paths without repeating the prefix.
- `Database/Migrations` is auto-loaded. Factories resolve to `App\Modules\{Name}\Database\Factories\{Model}Factory` (see ModulesServiceProvider::factoryFor).
- Modules talk to each other through domain events and interfaces only — never by reaching into another module's namespace.

## Never bind a PHP float into a SQL comparison
PDO binds a PHP float as a STRING, and SQLite orders every REAL below every TEXT value. So `havingRaw('avg(stars) >= ?', [4.0])` matches nothing — silently, with correct-looking SQL and bindings, and only on the engine the tests run against. Bind an integer instead: compare in hundredths, `havingRaw('avg(stars) * 100 >= ?', [(int) round($minimum * 100)])`. See `MechanicDirectory::idsRatedAtLeast()`.

Related: SQLite rejects `HAVING` over a select-list alias ("HAVING clause on a non-aggregate query"). Filter on an aggregate with its own `groupBy`, in a subquery fed to `whereIn`, rather than putting a HAVING on the alias an `addSelect` subquery created.
