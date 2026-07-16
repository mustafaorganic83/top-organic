<?php

namespace App\Modules\HR;

use Illuminate\Support\ServiceProvider;

/**
 * HR & Attendance module. Registers versioned API routes for employees,
 * departments and organization structure, attendance (GPS + photo + geo-fence),
 * leave requests, salary advances and long-term loans, payroll runs with salary
 * slips, performance evaluation, employee documents, and task management.
 */
class HrServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
