<?php

use App\Providers\AccountingServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\NotificationsServiceProvider;
use App\Providers\ProjectsServiceProvider;
use App\Providers\SmmServiceProvider;

return [
    AppServiceProvider::class,
    NotificationsServiceProvider::class,
    ProjectsServiceProvider::class,
    AccountingServiceProvider::class,
    SmmServiceProvider::class,
];
