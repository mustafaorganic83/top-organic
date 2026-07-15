<?php

namespace Tests\Feature\Foundation;

use App\Models\Branch;
use App\Models\Tenant;
use App\Support\Context\AppContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenant/branch context + global query scoping (architecture docs 02/03).
 * These assert that no query silently crosses a tenant or branch boundary,
 * and that the context is a shared singleton.
 */
class ContextScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_context_is_a_shared_singleton(): void
    {
        $this->assertSame(app(AppContext::class), app(AppContext::class));
    }

    public function test_tenant_scope_isolates_rows_by_resolved_tenant(): void
    {
        $tenantA = Tenant::create(['slug' => 'a', 'name' => 'A']);
        $tenantB = Tenant::create(['slug' => 'b', 'name' => 'B']);

        $this->withoutTenantScope(function () use ($tenantA, $tenantB): void {
            Branch::create(['tenant_id' => $tenantA->id, 'code' => 'A1', 'name' => 'A1']);
            Branch::create(['tenant_id' => $tenantB->id, 'code' => 'B1', 'name' => 'B1']);
        });

        app(AppContext::class)->setTenantId($tenantA->id);

        $this->assertSame(1, Branch::count());
        $this->assertSame($tenantA->id, Branch::first()->tenant_id);
    }

    public function test_branch_id_is_auto_stamped_from_context_on_create(): void
    {
        $tenant = Tenant::create(['slug' => 't', 'name' => 'T']);
        app(AppContext::class)->setTenantId($tenant->id);

        $branch = Branch::create(['code' => 'C1', 'name' => 'C1']);

        $this->assertSame($tenant->id, $branch->fresh()->tenant_id);
    }

    public function test_context_forget_clears_all_dimensions(): void
    {
        $context = app(AppContext::class);
        $context->setTenantId('t1')->setBranchId('b1')->setDeviceId('d1');

        $context->forget();

        $this->assertFalse($context->hasTenant());
        $this->assertFalse($context->hasBranch());
        $this->assertNull($context->deviceId());
    }

    private function withoutTenantScope(callable $callback): void
    {
        $context = app(AppContext::class);
        $context->forget();
        $callback();
    }
}
