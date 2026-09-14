<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Registered Modules
    |--------------------------------------------------------------------------
    |
    | MonaFindAuto is a modular monolith. Each entry below resolves to
    | App\Modules\{Name}\{Name}ServiceProvider, which is registered by
    | App\Providers\ModuleServiceProvider in the order listed here.
    |
    | Modules talk to each other through domain events and service interfaces
    | only — never by reaching into another module's internals.
    |
    */

    'enabled' => [
        'Identity',
        'Sellers',
        'Catalog',
        'Inventory',
        'Search',
        'Shopping',
        'Orders',
        'Payments',
        'Ledger',
        'Ratings',
        'Mechanics',
        'Messaging',
        'Admin',
        'Finance',
        /*
         * Last, so that every module has registered its personal-data source
         * and its erasure blocker before Privacy's registries are read.
         */
        'Privacy',
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Group Definitions
    |--------------------------------------------------------------------------
    |
    | Each module may ship routes/{web,seller,admin,api}.php. The definitions
    | below decide the middleware, URI prefix and route-name prefix applied to
    | each of those files.
    |
    */

    'route_groups' => [

        'web' => [
            'middleware' => ['web'],
            'prefix' => '',
            'name' => '',
        ],

        'seller' => [
            'middleware' => ['seller'],
            'prefix' => 'seller',
            'name' => 'seller.',
        ],

        'admin' => [
            'middleware' => ['admin'],
            'prefix' => 'admin',
            'name' => 'admin.',
        ],

        'api' => [
            'middleware' => ['api.v1'],
            'prefix' => 'api/v1',
            'name' => 'api.v1.',
        ],

    ],

];
