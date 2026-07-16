<?php

namespace App\Modules\Tables;

use Illuminate\Support\ServiceProvider;

/**
 * Reservation & Table Management module (architecture doc 01 FR-4). Registers
 * the module's versioned API routes: floor/room/table design, live table
 * occupancy, reservations across every channel, the waiting list, and the
 * tablet reception overview. Route definitions carry their own full paths and
 * middleware, mirroring the Sales module provider.
 */
class TablesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
