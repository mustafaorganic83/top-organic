<?php

use Illuminate\Support\Facades\Route;
use App\Livewire\Dashboard\DashboardPage;
use App\Livewire\Reports\ReportsPage;
use App\Livewire\Inventory\InventoryCostPage;
use App\Livewire\Production\ProductionPage;
use App\Livewire\Recipe\RecipePage;
use App\Livewire\Prepared\PreparedItemPage;
use App\Livewire\History\VersionHistoryPage;
use App\Livewire\Snapshots\SnapshotsPage;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/docs', function () {
    return view('api.scalar');
});

Route::get('/docs/openapi.yaml', function () {
    return response()->file(base_path('docs/api/openapi-v1.yaml'));
});

// ERP UI pages (Arabic-first, RTL)
Route::get('/dashboard', DashboardPage::class)->name('dashboard');
Route::get('/reports', ReportsPage::class)->name('reports');
Route::get('/inventory-cost', InventoryCostPage::class)->name('inventory.cost');
Route::get('/production', ProductionPage::class)->name('production');
Route::get('/recipes', RecipePage::class)->name('recipes');
Route::get('/prepared-items', PreparedItemPage::class)->name('prepared');
Route::get('/version-history', VersionHistoryPage::class)->name('history');
Route::get('/snapshots', SnapshotsPage::class)->name('snapshots');
