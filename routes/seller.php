<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Seller portal routes
|--------------------------------------------------------------------------
|
| Mounted at /seller with the "seller" middleware group (web + auth +
| verified + role check) and the "seller." route-name prefix.
|
| Feature routes belong in the owning module's routes/seller.php; only
| cross-cutting portal shell routes live here.
|
*/

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'seller/Dashboard')->name('dashboard');
