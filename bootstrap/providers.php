<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\IntegrationServiceProvider;
use App\Providers\ModulesServiceProvider;
use App\Support\Alerts\AlertServiceProvider;

return [
    AppServiceProvider::class,
    AlertServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    IntegrationServiceProvider::class,
    ModulesServiceProvider::class,
];
