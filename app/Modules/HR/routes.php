<?php

declare(strict_types=1);

use App\Modules\HR\Http\Controllers\AttendanceController;
use App\Modules\HR\Http\Controllers\DepartmentController;
use App\Modules\HR\Http\Controllers\DocumentController;
use App\Modules\HR\Http\Controllers\EmployeeController;
use App\Modules\HR\Http\Controllers\GeofenceController;
use App\Modules\HR\Http\Controllers\LeaveController;
use App\Modules\HR\Http\Controllers\LoanController;
use App\Modules\HR\Http\Controllers\PayrollController;
use App\Modules\HR\Http\Controllers\PerformanceController;
use App\Modules\HR\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:api', 'identity.context'])
    ->prefix('api/v1/hr')
    ->name('hr.')
    ->group(function (): void {

        // ── Departments ──────────────────────────────────────────────────
        Route::prefix('departments')->group(function (): void {
            Route::get('/', [DepartmentController::class, 'index'])->middleware('permission:hr.employees.view');
            Route::post('/', [DepartmentController::class, 'store'])->middleware('permission:hr.employees.manage');
            Route::get('{department}', [DepartmentController::class, 'show'])->whereUlid('department')->middleware('permission:hr.employees.view');
            Route::patch('{department}', [DepartmentController::class, 'update'])->whereUlid('department')->middleware('permission:hr.employees.manage');
        });

        // ── Employees ────────────────────────────────────────────────────
        Route::prefix('employees')->group(function (): void {
            Route::get('/', [EmployeeController::class, 'index'])->middleware('permission:hr.employees.view');
            Route::post('/', [EmployeeController::class, 'store'])->middleware('permission:hr.employees.manage');
            Route::get('{employee}', [EmployeeController::class, 'show'])->whereUlid('employee')->middleware('permission:hr.employees.view');
            Route::patch('{employee}', [EmployeeController::class, 'update'])->whereUlid('employee')->middleware('permission:hr.employees.manage');
            Route::get('{employee}/history', [EmployeeController::class, 'history'])->whereUlid('employee')->middleware('permission:hr.employees.view');

            // Attendance (nested under employee for context)
            Route::get('{employee}/attendance', [AttendanceController::class, 'index'])->whereUlid('employee')->middleware('permission:hr.attendance.view');

            // Documents
            Route::get('{employee}/documents', [DocumentController::class, 'index'])->whereUlid('employee')->middleware('permission:hr.documents.manage');
            Route::post('{employee}/documents', [DocumentController::class, 'store'])->whereUlid('employee')->middleware('permission:hr.documents.manage');
            Route::delete('{employee}/documents/{document}', [DocumentController::class, 'destroy'])->whereUlid('employee')->whereUlid('document')->middleware('permission:hr.documents.manage');
        });

        // ── Attendance (check-in/out) ────────────────────────────────────
        Route::prefix('attendance')->group(function (): void {
            Route::post('check-in', [AttendanceController::class, 'checkIn'])->middleware('permission:hr.attendance.record');
            Route::post('{attendance}/check-out', [AttendanceController::class, 'checkOut'])->whereUlid('attendance')->middleware('permission:hr.attendance.record');
        });

        // ── Geofences ────────────────────────────────────────────────────
        Route::prefix('geofences')->group(function (): void {
            Route::get('/', [GeofenceController::class, 'index'])->middleware('permission:hr.attendance.manage');
            Route::post('/', [GeofenceController::class, 'store'])->middleware('permission:hr.attendance.manage');
            Route::patch('{geofence}', [GeofenceController::class, 'update'])->whereUlid('geofence')->middleware('permission:hr.attendance.manage');
        });

        // ── Leave Requests ───────────────────────────────────────────────
        Route::prefix('leave')->group(function (): void {
            Route::get('/', [LeaveController::class, 'index'])->middleware('permission:hr.leave.view');
            Route::post('/', [LeaveController::class, 'store'])->middleware('permission:hr.leave.request');
            Route::get('{leave}', [LeaveController::class, 'show'])->whereUlid('leave')->middleware('permission:hr.leave.view');
            Route::post('{leave}/approve', [LeaveController::class, 'approve'])->whereUlid('leave')->middleware('permission:hr.leave.approve');
            Route::post('{leave}/reject', [LeaveController::class, 'reject'])->whereUlid('leave')->middleware('permission:hr.leave.approve');
        });

        // ── Loans ────────────────────────────────────────────────────────
        Route::prefix('loans')->group(function (): void {
            Route::get('/', [LoanController::class, 'index'])->middleware('permission:hr.loans.view');
            Route::post('/', [LoanController::class, 'store'])->middleware('permission:hr.loans.manage');
            Route::get('{loan}', [LoanController::class, 'show'])->whereUlid('loan')->middleware('permission:hr.loans.view');
            Route::post('{loan}/approve', [LoanController::class, 'approve'])->whereUlid('loan')->middleware('permission:hr.loans.approve');
        });

        // ── Payroll ──────────────────────────────────────────────────────
        Route::prefix('payroll')->group(function (): void {
            Route::get('/', [PayrollController::class, 'index'])->middleware('permission:hr.payroll.view');
            Route::post('/', [PayrollController::class, 'store'])->middleware('permission:hr.payroll.run');
            Route::get('{run}', [PayrollController::class, 'show'])->whereUlid('run')->middleware('permission:hr.payroll.view');
            Route::post('{run}/calculate', [PayrollController::class, 'calculate'])->whereUlid('run')->middleware('permission:hr.payroll.run');
            Route::post('{run}/approve', [PayrollController::class, 'approve'])->whereUlid('run')->middleware('permission:hr.payroll.approve');
            Route::get('{run}/payslip', [PayrollController::class, 'payslip'])->whereUlid('run')->middleware('permission:hr.payroll.view');
            Route::post('{run}/adjustments', [PayrollController::class, 'addAdjustment'])->whereUlid('run')->middleware('permission:hr.payroll.run');
        });

        // ── Performance ──────────────────────────────────────────────────
        Route::prefix('performance')->group(function (): void {
            Route::get('/', [PerformanceController::class, 'index'])->middleware('permission:hr.performance.manage');
            Route::post('/', [PerformanceController::class, 'store'])->middleware('permission:hr.performance.manage');
            Route::get('{review}', [PerformanceController::class, 'show'])->whereUlid('review')->middleware('permission:hr.performance.manage');
            Route::post('{review}/acknowledge', [PerformanceController::class, 'acknowledge'])->whereUlid('review')->middleware('permission:hr.performance.manage');
        });

        // ── Tasks ────────────────────────────────────────────────────────
        Route::prefix('tasks')->group(function (): void {
            Route::get('/', [TaskController::class, 'index'])->middleware('permission:hr.tasks.view');
            Route::post('/', [TaskController::class, 'store'])->middleware('permission:hr.tasks.manage');
            Route::get('{task}', [TaskController::class, 'show'])->whereUlid('task')->middleware('permission:hr.tasks.view');
            Route::patch('{task}', [TaskController::class, 'update'])->whereUlid('task')->middleware('permission:hr.tasks.manage');
            Route::post('{task}/complete', [TaskController::class, 'complete'])->whereUlid('task')->middleware('permission:hr.tasks.manage');
        });
    });
