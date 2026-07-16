<?php

use App\Modules\Identity\Http\Controllers\AuditController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\AuthorizationController;
use App\Modules\Identity\Http\Controllers\DeviceController;
use App\Modules\Identity\Http\Controllers\OfflineGrantController;
use App\Modules\Identity\Http\Controllers\RoleController;
use App\Modules\Identity\Http\Controllers\WebSessionController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1')->group(function (): void {
    Route::middleware('throttle:identity-auth')->group(function (): void {
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/mfa/complete', [AuthController::class, 'completeMfa']);
        Route::post('auth/refresh', [AuthController::class, 'refresh']);
    });
    Route::post('devices/register', [DeviceController::class, 'register'])
        ->middleware('throttle:identity-device-registration');

    Route::middleware(['auth:api', 'identity.context'])->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/logout-all', [AuthController::class, 'logoutAll']);
        Route::post('auth/password', [AuthController::class, 'changePassword']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);
        Route::get('sessions', [AuthController::class, 'sessions']);
        Route::delete('sessions/{session}', [AuthController::class, 'revokeSession'])->whereUlid('session');
        Route::post('devices/{device}/trust', [AuthController::class, 'rememberDevice'])->whereUlid('device');
        Route::delete('devices/{device}/trust', [AuthController::class, 'revokeRememberedDevice'])->whereUlid('device');

        Route::post('offline-grants', [OfflineGrantController::class, 'issue']);
        Route::get('offline-grants', [OfflineGrantController::class, 'index']);
        Route::delete('offline-grants/{grant}', [OfflineGrantController::class, 'revoke'])->whereUlid('grant');
        Route::post('offline-grants/{grant}/receipts', [OfflineGrantController::class, 'receipt'])->whereUlid('grant');

        Route::prefix('admin')->group(function (): void {
            Route::get('permission-groups', [AuthorizationController::class, 'catalog'])
                ->middleware('permission:identity.permissions.view');
            Route::get('permission-catalog', [AuthorizationController::class, 'catalog'])
                ->middleware('permission:identity.permissions.view');
            Route::get('permissions', [AuthorizationController::class, 'permissions'])
                ->middleware('permission:identity.permissions.view');

            Route::get('roles', [RoleController::class, 'index'])->middleware('permission:identity.roles.view');
            Route::post('roles', [RoleController::class, 'store'])->middleware('permission:identity.roles.manage');
            Route::get('roles/{role}', [RoleController::class, 'show'])->whereUlid('role')
                ->middleware('permission:identity.roles.view');
            Route::patch('roles/{role}', [RoleController::class, 'update'])->whereUlid('role')
                ->middleware('permission:identity.roles.manage');
            Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->whereUlid('role')
                ->middleware('permission:identity.roles.manage');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->whereUlid('role')
                ->middleware('permission:identity.roles.manage');

            Route::post('users/{user}/branches/{branch}/roles/{role}', [AuthorizationController::class, 'grant'])
                ->whereUlid(['user', 'branch', 'role'])->middleware('permission:identity.roles.assign');
            Route::delete('role-grants/{grant}', [AuthorizationController::class, 'revoke'])
                ->whereUlid('grant')->middleware('permission:identity.roles.assign');

            Route::get('devices', [DeviceController::class, 'index'])->middleware('permission:identity.devices.view');
            Route::get('devices/{device}', [DeviceController::class, 'show'])->whereUlid('device')
                ->middleware('permission:identity.devices.view');
            Route::post('devices/{device}/approve', [DeviceController::class, 'approve'])->whereUlid('device')
                ->middleware('permission:identity.devices.manage');
            Route::post('devices/{device}/revoke', [DeviceController::class, 'revoke'])->whereUlid('device')
                ->middleware('permission:identity.devices.manage');

            Route::get('audit', [AuditController::class, 'index'])->middleware('permission:identity.audit.view');
            Route::get('audit-logs', [AuditController::class, 'index'])->middleware('permission:identity.audit.view');
        });
    });
});

Route::middleware('web')->group(function (): void {
    Route::post('login', [WebSessionController::class, 'login'])->middleware('throttle:identity-auth');
    Route::post('logout', [WebSessionController::class, 'logout'])->middleware('auth:web');
});
