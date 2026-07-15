<?php

use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Menu\MenuServiceProvider;
use App\Modules\Orders\OrdersServiceProvider;
use App\Modules\Reports\ReportsServiceProvider;
use App\Modules\Sales\SalesServiceProvider;
use App\Modules\Staff\StaffServiceProvider;
use App\Modules\Tables\TablesServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,

    // Domain module skeletons (architecture doc 01). Feature endpoints are
    // added under each module as later steps land.
    MenuServiceProvider::class,
    OrdersServiceProvider::class,
    TablesServiceProvider::class,
    BillingServiceProvider::class,
    StaffServiceProvider::class,
    ReportsServiceProvider::class,
    SalesServiceProvider::class,
];
