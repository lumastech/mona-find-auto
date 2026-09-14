<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Inventory — Admin routes
|--------------------------------------------------------------------------
|
| Staff console routes, mounted at /admin behind the `admin` middleware group.
| Middleware, URI prefix and route-name prefix come from the "admin" entry
| in config/modules.php, so declare paths here without repeating them.
|
*/

use App\Modules\Inventory\Http\Controllers\Admin\StaleStockController;
use Illuminate\Support\Facades\Route;

/* Who has stopped confirming their stock. Read-only: see the controller. */
Route::get('stale-stock', StaleStockController::class)->name('stale-stock');
