<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR & Attendance module. Covers employees, departments and organization
 * structure, attendance (GPS + photo + geo-fence), leave requests, salary
 * advances and long-term loans, payroll runs with salary slips, bonuses and
 * penalties, performance evaluation, employee documents, task management, and
 * the append-only employee history trail.
 *
 * Money: integer minor units. Quantities/hours: decimal. GPS: decimal(10,7).
 * lock_version for optimistic concurrency. Lifecycle statuses per table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Departments (org structure via self-referencing parent_id) ──────
        Schema::create('departments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('parent_id')->nullable(); // org structure
            $table->string('code', 64);
            $table->string('name', 200);
            $table->foreignUlid('manager_employee_id')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 24)->default('active'); // active, inactive
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code'], 'uq_departments_code');
            $table->index(['tenant_id', 'parent_id'], 'ix_departments_parent');
        });

        // ── Employees (branch-scoped) ───────────────────────────────────────
        Schema::create('employees', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 64);
            $table->string('first_name', 120);
            $table->string('last_name', 120);
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('employment_type', 24)->default('full_time'); // full_time, part_time, contract
            $table->date('hire_date')->nullable();
            $table->date('termination_date')->nullable();
            $table->unsignedBigInteger('base_salary_amount')->default(0);
            $table->string('currency', 3)->default('IQD');
            $table->string('status', 24)->default('active'); // active, inactive, terminated
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'code'], 'uq_employees_code');
            $table->index(['tenant_id', 'branch_id', 'department_id'], 'ix_employees_department');
            $table->index(['tenant_id', 'branch_id', 'status'], 'ix_employees_status');
        });

        // ── Geo-fences (attendance boundary check) ──────────────────────────
        Schema::create('geofences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->decimal('center_lat', 10, 7);
            $table->decimal('center_lng', 10, 7);
            $table->unsignedInteger('radius_meters')->default(100);
            $table->string('status', 24)->default('active'); // active, inactive
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'branch_id', 'status'], 'ix_geofences_status');
        });

        // ── Attendance (check_in → check_out, GPS + photo + geo-fence) ──────
        Schema::create('attendances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('employee_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('geofence_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date');
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->decimal('check_in_lat', 10, 7)->nullable();
            $table->decimal('check_in_lng', 10, 7)->nullable();
            $table->decimal('check_out_lat', 10, 7)->nullable();
            $table->decimal('check_out_lng', 10, 7)->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->boolean('within_geofence')->default(false);
            $table->decimal('worked_hours', 8, 2)->default(0);
            $table->string('status', 24)->default('checked_in'); // checked_in, checked_out
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'employee_id', 'work_date'], 'uq_attendance_day');
            $table->index(['tenant_id', 'branch_id', 'status'], 'ix_attendance_status');
        });

        // ── Leave requests (draft → submitted → approved/rejected/cancelled)
        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('employee_id')->constrained()->restrictOnDelete();
            $table->string('type', 24)->default('annual'); // annual, sick, unpaid, other
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days', 6, 2)->default(0);
            $table->text('reason')->nullable();
            $table->string('status', 24)->default('draft'); // draft, submitted, approved, rejected, cancelled
            $table->foreignUlid('approved_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'branch_id', 'employee_id', 'status'], 'ix_leave_status');
        });

        // ── Employee loans (salary advance + long term) ─────────────────────
        // requested → approved → active → settled / rejected
        Schema::create('employee_loans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('employee_id')->constrained()->restrictOnDelete();
            $table->string('type', 24)->default('salary_advance'); // salary_advance, long_term
            $table->string('reference', 64);
            $table->unsignedBigInteger('principal_amount');
            $table->unsignedBigInteger('outstanding_amount')->default(0);
            $table->unsignedBigInteger('installment_amount')->default(0);
            $table->unsignedInteger('installments_count')->default(1);
            $table->string('currency', 3)->default('IQD');
            $table->text('reason')->nullable();
            $table->string('status', 24)->default('requested'); // requested, approved, active, settled, rejected
            $table->foreignUlid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'reference'], 'uq_employee_loans_reference');
            $table->index(['tenant_id', 'branch_id', 'employee_id', 'status'], 'ix_employee_loans_status');
        });

        // ── Payroll runs (draft → calculated → approved → paid) ─────────────
        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->string('reference', 64);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 24)->default('draft'); // draft, calculated, approved, paid
            $table->string('currency', 3)->default('IQD');
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->text('notes')->nullable();
            $table->foreignUlid('approved_by')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'reference'], 'uq_payroll_runs_reference');
            $table->index(['tenant_id', 'branch_id', 'status'], 'ix_payroll_runs_status');
        });

        // ── Payslips (salary slip per employee per run) ─────────────────────
        Schema::create('payslips', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('payroll_run_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('employee_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('base_amount')->default(0);
            $table->unsignedBigInteger('bonus_amount')->default(0);
            $table->unsignedBigInteger('penalty_amount')->default(0);
            $table->unsignedBigInteger('loan_deduction_amount')->default(0);
            $table->unsignedBigInteger('gross_amount')->default(0);
            $table->unsignedBigInteger('deductions_amount')->default(0);
            $table->unsignedBigInteger('net_amount')->default(0);
            $table->string('currency', 3)->default('IQD');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'payroll_run_id', 'employee_id'], 'uq_payslips_run_employee');
        });

        // ── Payroll adjustments (bonuses / penalties) added before payslip generation ─
        Schema::create('payroll_adjustments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('payroll_run_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('employee_id')->constrained()->restrictOnDelete();
            $table->string('type', 24); // bonus, penalty
            $table->unsignedBigInteger('amount');
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'payroll_run_id', 'employee_id'], 'ix_payroll_adjustments_run');
        });

        // ── Performance reviews (submitted → acknowledged) ──────────────────
        Schema::create('performance_reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('employee_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('reviewer_id')->nullable();
            $table->date('review_period_start')->nullable();
            $table->date('review_period_end')->nullable();
            $table->unsignedTinyInteger('score')->nullable(); // 0-100
            $table->string('rating', 32)->nullable();
            $table->text('comments')->nullable();
            $table->string('status', 24)->default('submitted'); // submitted, acknowledged
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'branch_id', 'employee_id', 'status'], 'ix_performance_status');
        });

        // ── Employee documents ──────────────────────────────────────────────
        Schema::create('employee_documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('employee_id')->constrained()->restrictOnDelete();
            $table->string('type', 40)->default('other'); // contract, id, certificate, other
            $table->string('title', 200);
            $table->string('file_path', 500);
            $table->date('issued_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'branch_id', 'employee_id'], 'ix_employee_documents_employee');
        });

        // ── HR tasks (open → in_progress → done / cancelled) ────────────────
        Schema::create('hr_tasks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('assigned_to')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('priority', 24)->default('normal'); // low, normal, high, urgent
            $table->date('due_date')->nullable();
            $table->string('status', 24)->default('open'); // open, in_progress, done, cancelled
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'branch_id', 'status'], 'ix_hr_tasks_status');
        });

        // ── Employee history (append-only audit trail) ──────────────────────
        Schema::create('employee_history', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->string('entity_type', 64);
            $table->ulid('entity_id');
            $table->string('action', 64);
            $table->json('changes')->nullable();
            $table->foreignUlid('actor_id')->nullable();
            $table->foreignUlid('device_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['tenant_id', 'branch_id', 'entity_type', 'entity_id'], 'ix_employee_history_entity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_history');
        Schema::dropIfExists('hr_tasks');
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('performance_reviews');
        Schema::dropIfExists('payroll_adjustments');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('employee_loans');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('geofences');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('departments');
    }
};
