<?php

namespace App\Modules\Kitchen;

use Illuminate\Support\ServiceProvider;

/**
 * Kitchen Management module (architecture doc 01 FR-5). Registers the module's
 * versioned API routes: station/screen configuration, the phase queues
 * (preparation, cooking, ready, served), the kitchen timer and prep-time
 * board, chef assignment and serving, priority handling, and kitchen
 * analytics/KPIs. The module reuses the Sales KDS aggregate (kds_* tables) so
 * the POS → kitchen flow is a single source of truth.
 */
class KitchenServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
