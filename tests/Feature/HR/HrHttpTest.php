<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * HR & Attendance feature tests: departments, employees, attendance check-in/out,
 * geofences, leave requests, loans, payroll runs, performance reviews, documents, tasks.
 */
class HrHttpTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private User $user;

    private Device $device;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::create(['slug' => 'hr-http', 'name' => 'HR HTTP']);
        $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'code' => 'HQ', 'name' => 'HQ']);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'hr@example.com',
            'password' => 'Password123',
        ]);
        $this->branch->users()->attach($this->user);
        $this->user->assignRole('admin');
        TenantSecurityPolicy::create(['tenant_id' => $this->tenant->id]);
        $this->device = Device::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'code' => 'HR-1', 'name' => 'HR Device', 'type' => 'pos', 'status' => 'authorized',
            'key_fingerprint' => hash('sha256', 'hr-device'),
        ]);
        $this->token = $this->login($this->user, $this->device->id);
    }

    // ── Departments ────────────────────────────────────────────────────────

    public function test_create_and_list_departments(): void
    {
        $r = $this->apiPost('/api/v1/hr/departments', ['code' => 'OPS', 'name' => 'Operations']);
        $r->assertStatus(201)->assertJsonPath('data.code', 'OPS');

        $this->apiGet('/api/v1/hr/departments')->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_update_department(): void
    {
        $id = $this->createDepartment('IT', 'Information Technology');

        $this->apiPatch("/api/v1/hr/departments/{$id}", [
            'name' => 'IT Department', 'expected_version' => 0,
        ])->assertStatus(200)->assertJsonPath('data.name', 'IT Department');
    }

    // ── Employees ──────────────────────────────────────────────────────────

    public function test_hire_and_list_employees(): void
    {
        $deptId = $this->createDepartment('HR', 'Human Resources');

        $r = $this->apiPost('/api/v1/hr/employees', [
            'employee_number' => 'EMP-001', 'first_name' => 'Ahmad', 'last_name' => 'Ali',
            'phone' => '+966500000001', 'position' => 'Manager', 'hire_date' => '2026-01-01',
            'employment_type' => 'full_time', 'department_id' => $deptId,
            'base_salary_amount' => 500000, 'currency' => 'SAR',
        ]);
        $r->assertStatus(201)->assertJsonPath('data.code', 'EMP-001');

        $this->apiGet('/api/v1/hr/employees')->assertStatus(200)->assertJsonStructure(['data']);
    }

    public function test_update_employee(): void
    {
        $id = $this->createEmployee('EMP-002');

        $this->apiPatch("/api/v1/hr/employees/{$id}", [
            'position' => 'Senior Manager', 'expected_version' => 0,
        ])->assertStatus(200)->assertJsonPath('data.job_title', 'Senior Manager');
    }

    public function test_employee_history_is_accessible(): void
    {
        $id = $this->createEmployee('EMP-003');

        $this->apiGet("/api/v1/hr/employees/{$id}/history")->assertStatus(200);
    }

    // ── Attendance ─────────────────────────────────────────────────────────

    public function test_check_in_and_check_out(): void
    {
        $empId = $this->createEmployee('EMP-ATT-01');

        $r = $this->apiPost('/api/v1/hr/attendance/check-in', [
            'employee_id' => $empId, 'work_date' => '2026-07-01',
            'lat' => 24.7136, 'lng' => 46.6753,
        ]);
        $r->assertStatus(201)->assertJsonPath('data.employee_id', $empId);
        $attId = $r->json('data.id');

        $this->apiPost("/api/v1/hr/attendance/{$attId}/check-out", [
            'lat' => 24.7136, 'lng' => 46.6753, 'expected_version' => 0,
        ])->assertStatus(200)->assertJsonPath('data.status', 'checked_out');
    }

    public function test_list_employee_attendance(): void
    {
        $empId = $this->createEmployee('EMP-ATT-02');
        $this->apiPost('/api/v1/hr/attendance/check-in', ['employee_id' => $empId]);

        $this->apiGet("/api/v1/hr/employees/{$empId}/attendance")->assertStatus(200);
    }

    // ── Geofences ──────────────────────────────────────────────────────────

    public function test_create_and_update_geofence(): void
    {
        $r = $this->apiPost('/api/v1/hr/geofences', [
            'name' => 'HQ Zone', 'center_lat' => 24.7136, 'center_lng' => 46.6753, 'radius_meters' => 200,
        ]);
        $r->assertStatus(201)->assertJsonPath('data.name', 'HQ Zone');
        $id = $r->json('data.id');

        $this->apiPatch("/api/v1/hr/geofences/{$id}", [
            'radius_meters' => 300, 'expected_version' => 0,
        ])->assertStatus(200)->assertJsonPath('data.radius_meters', 300);
    }

    // ── Leave Requests ─────────────────────────────────────────────────────

    public function test_leave_request_lifecycle(): void
    {
        $empId = $this->createEmployee('EMP-LV-01');

        $r = $this->apiPost('/api/v1/hr/leave', [
            'employee_id' => $empId, 'leave_type' => 'annual',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-05', 'days' => 5,
        ]);
        $r->assertStatus(201)->assertJsonPath('data.status', 'submitted');
        $leaveId = $r->json('data.id');

        $this->apiGet("/api/v1/hr/leave/{$leaveId}")->assertStatus(200);

        $this->apiPost("/api/v1/hr/leave/{$leaveId}/approve", ['expected_version' => 0])
            ->assertStatus(200)->assertJsonPath('data.status', 'approved');
    }

    public function test_leave_rejection(): void
    {
        $empId = $this->createEmployee('EMP-LV-02');
        $leaveId = $this->createLeave($empId);

        $this->apiPost("/api/v1/hr/leave/{$leaveId}/reject", [
            'expected_version' => 0, 'reason' => 'Staffing constraints',
        ])->assertStatus(200)->assertJsonPath('data.status', 'rejected');
    }

    // ── Loans ──────────────────────────────────────────────────────────────

    public function test_loan_request_and_approval(): void
    {
        $empId = $this->createEmployee('EMP-LN-01');

        $r = $this->apiPost('/api/v1/hr/loans', [
            'employee_id' => $empId, 'loan_type' => 'advance',
            'amount' => 100000, 'installments' => 5, 'purpose' => 'Medical',
        ]);
        $r->assertStatus(201)->assertJsonPath('data.status', 'requested');
        $loanId = $r->json('data.id');

        $this->apiGet("/api/v1/hr/loans/{$loanId}")->assertStatus(200);

        $this->apiPost("/api/v1/hr/loans/{$loanId}/approve", ['expected_version' => 0])
            ->assertStatus(200)->assertJsonPath('data.status', 'active');
    }

    // ── Payroll ────────────────────────────────────────────────────────────

    public function test_payroll_run_lifecycle(): void
    {
        $empId = $this->createEmployee('EMP-PAY-01', 300000);

        // Create draft run
        $r = $this->apiPost('/api/v1/hr/payroll', [
            'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
        ]);
        $r->assertStatus(201)->assertJsonPath('data.status', 'draft');
        $runId = $r->json('data.id');

        // Add bonus
        $this->apiPost("/api/v1/hr/payroll/{$runId}/adjustments", [
            'employee_id' => $empId, 'type' => 'bonus', 'amount' => 50000, 'reason' => 'Performance',
        ])->assertStatus(201);

        // Calculate
        $this->apiPost("/api/v1/hr/payroll/{$runId}/calculate", ['expected_version' => 0])
            ->assertStatus(200)->assertJsonPath('data.status', 'calculated');

        // Approve
        $this->apiPost("/api/v1/hr/payroll/{$runId}/approve", ['expected_version' => 1])
            ->assertStatus(200)->assertJsonPath('data.status', 'approved');

        // Fetch payslip
        $this->apiGet("/api/v1/hr/payroll/{$runId}/payslip?employee_id={$empId}")
            ->assertStatus(200)->assertJsonPath('data.gross_amount', 350000);
    }

    // ── Performance ────────────────────────────────────────────────────────

    public function test_performance_review_lifecycle(): void
    {
        $empId = $this->createEmployee('EMP-PERF-01');

        $r = $this->apiPost('/api/v1/hr/performance', [
            'employee_id' => $empId, 'score' => 85, 'rating' => 'excellent',
            'review_period_start' => '2026-01-01', 'review_period_end' => '2026-06-30',
            'comments' => 'Great performer.',
        ]);
        $r->assertStatus(201)->assertJsonPath('data.status', 'submitted');
        $reviewId = $r->json('data.id');

        $this->apiPost("/api/v1/hr/performance/{$reviewId}/acknowledge", ['expected_version' => 0])
            ->assertStatus(200)->assertJsonPath('data.status', 'acknowledged');
    }

    // ── Documents ──────────────────────────────────────────────────────────

    public function test_document_upload_and_delete(): void
    {
        $empId = $this->createEmployee('EMP-DOC-01');

        $r = $this->apiPost("/api/v1/hr/employees/{$empId}/documents", [
            'document_type' => 'contract', 'title' => 'Employment Contract',
            'file_path' => '/storage/contracts/emp-001.pdf', 'expiry_date' => '2027-01-01',
        ]);
        $r->assertStatus(201)->assertJsonPath('data.title', 'Employment Contract');
        $docId = $r->json('data.id');

        $this->apiGet("/api/v1/hr/employees/{$empId}/documents")->assertStatus(200);

        $this->withToken($this->token)->withHeaders([
            'X-Branch-Id' => $this->branch->id, 'X-Device-Id' => $this->device->id,
        ])->deleteJson("/api/v1/hr/employees/{$empId}/documents/{$docId}")
            ->assertStatus(200)->assertJsonPath('data.deleted', true);
    }

    // ── Tasks ──────────────────────────────────────────────────────────────

    public function test_task_lifecycle(): void
    {
        $empId = $this->createEmployee('EMP-TSK-01');

        $r = $this->apiPost('/api/v1/hr/tasks', [
            'employee_id' => $empId, 'title' => 'Submit monthly report',
            'due_date' => '2026-07-31', 'priority' => 'high',
        ]);
        $r->assertStatus(201)->assertJsonPath('data.status', 'open');
        $taskId = $r->json('data.id');

        $this->apiPatch("/api/v1/hr/tasks/{$taskId}", [
            'status' => 'in_progress', 'expected_version' => 0,
        ])->assertStatus(200)->assertJsonPath('data.status', 'in_progress');

        $this->apiPost("/api/v1/hr/tasks/{$taskId}/complete", ['expected_version' => 1])
            ->assertStatus(200)->assertJsonPath('data.status', 'done');
    }

    // ── Scope isolation ────────────────────────────────────────────────────

    public function test_employees_scoped_to_tenant(): void
    {
        $other = Tenant::create(['slug' => 'hr-other', 'name' => 'Other']);
        Branch::create(['tenant_id' => $other->id, 'code' => 'B2', 'name' => 'B2']);

        $this->createEmployee('EMP-SCOPE-01');

        // Our list should show 1, not cross-tenant
        $data = $this->apiGet('/api/v1/hr/employees')->json('data');
        $this->assertCount(1, $data);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function login(User $user, string $deviceId): string
    {
        $r = $this->postJson('/api/v1/auth/login', [
            'identifier' => $user->email, 'password' => 'Password123',
            'tenant_slug' => $this->tenant->slug, 'device_id' => $deviceId,
        ]);

        return $r->json('data.access_token') ?? '';
    }

    private function apiGet(string $url): TestResponse
    {
        return $this->withToken($this->token)->withHeaders([
            'X-Branch-Id' => $this->branch->id, 'X-Device-Id' => $this->device->id,
        ])->getJson($url);
    }

    private function apiPost(string $url, array $data = []): TestResponse
    {
        return $this->withToken($this->token)->withHeaders([
            'X-Branch-Id' => $this->branch->id, 'X-Device-Id' => $this->device->id,
        ])->postJson($url, $data);
    }

    private function apiPatch(string $url, array $data): TestResponse
    {
        return $this->withToken($this->token)->withHeaders([
            'X-Branch-Id' => $this->branch->id, 'X-Device-Id' => $this->device->id,
        ])->patchJson($url, $data);
    }

    private function createDepartment(string $code, string $name): string
    {
        return $this->apiPost('/api/v1/hr/departments', ['code' => $code, 'name' => $name])
            ->json('data.id');
    }

    private function createEmployee(string $number, int $salary = 200000): string
    {
        return $this->apiPost('/api/v1/hr/employees', [
            'employee_number' => $number, 'first_name' => 'Test', 'last_name' => 'User',
            'employment_type' => 'full_time', 'base_salary_amount' => $salary, 'currency' => 'SAR',
        ])->json('data.id');
    }

    private function createLeave(string $empId): string
    {
        return $this->apiPost('/api/v1/hr/leave', [
            'employee_id' => $empId, 'leave_type' => 'sick',
            'start_date' => '2026-09-01', 'end_date' => '2026-09-02', 'days' => 1,
        ])->json('data.id');
    }
}
