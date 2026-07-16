<?php

use App\Modules\Accounting\AccountingServiceProvider;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\HR\HrServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Kitchen\KitchenServiceProvider;
use App\Modules\Menu\MenuServiceProvider;
use App\Modules\Orders\OrdersServiceProvider;
use App\Modules\Procurement\ProcurementServiceProvider;
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
    InventoryServiceProvider::class,
    OrdersServiceProvider::class,
    TablesServiceProvider::class,
    KitchenServiceProvider::class,
    BillingServiceProvider::class,
    StaffServiceProvider::class,
    ReportsServiceProvider::class,
    SalesServiceProvider::class,
    AccountingServiceProvider::class,
    ProcurementServiceProvider::class,
    HrServiceProvider::class,
];
