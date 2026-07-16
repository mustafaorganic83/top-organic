<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Http\IdentityResponse;
use App\Modules\Identity\Http\Requests\IndexRequest;
use App\Modules\Identity\Http\Requests\PermissionSyncRequest;
use App\Modules\Identity\Http\Requests\RoleRequest;
use App\Modules\Identity\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoleController extends Controller
{
    public function index(IndexRequest $request): JsonResponse
    {
        $query = Role::query()->where('tenant_id', $request->user('api')->tenant_id)->with('permissions');
        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }
        $paginator = $query->orderBy('name')->paginate($request->perPage());

        return response()->json(['data' => collect($paginator->items())->map(IdentityResponse::role(...)),
            'meta' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(),
                'total' => $paginator->total(), 'last_page' => $paginator->lastPage()]]);
    }

    public function store(RoleRequest $request, RoleService $roles): JsonResponse
    {
        $tenant = $this->tenant($request);
        $attributes = $request->safe()->except('permission_ids');
        $this->assertUniqueName($tenant->getKey(), $attributes['name']);
        $role = $roles->create($tenant, $request->user('api'), $attributes, $this->permissionIds($request));

        return response()->json(['data' => IdentityResponse::role($role)], 201);
    }

    public function show(string $role, Request $request): JsonResponse
    {
        return response()->json(['data' => IdentityResponse::role($this->role($role, $request)->load('permissions'))]);
    }

    public function update(string $role, RoleRequest $request, RoleService $roles): JsonResponse
    {
        if ($request->filled('name')) {
            $this->assertUniqueName($request->user('api')->tenant_id, $request->string('name')->toString(), $role);
        }
        $updated = $roles->update($this->tenant($request), $request->user('api'), $role,
            $request->safe()->except('permission_ids'));
        if ($request->has('permission_ids')) {
            $updated = $roles->syncPermissions($this->tenant($request), $request->user('api'), $role,
                $this->permissionIds($request));
        }

        return response()->json(['data' => IdentityResponse::role($updated->load('permissions'))]);
    }

    public function syncPermissions(
        string $role,
        PermissionSyncRequest $request,
        RoleService $roles,
    ): JsonResponse {
        $updated = $roles->syncPermissions(
            $this->tenant($request), $request->user('api'), $role, $this->permissionIds($request),
        );

        return response()->json(['data' => IdentityResponse::role($updated)]);
    }

    public function destroy(string $role, Request $request, RoleService $roles): JsonResponse
    {
        $roles->delete($this->tenant($request), $request->user('api'), $role);

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function tenant(Request $request): Tenant
    {
        return Tenant::query()->findOrFail($request->user('api')->tenant_id);
    }

    private function role(string $publicId, Request $request): Role
    {
        return Role::query()->where('tenant_id', $request->user('api')->tenant_id)
            ->where('public_id', $publicId)->first()
            ?? throw new IdentityException('ROLE_NOT_FOUND', 404, 'The role was not found.');
    }

    private function permissionIds(Request $request): array
    {
        $publicIds = $request->validated('permission_ids', []);
        $permissions = Permission::query()->whereIn('public_id', $publicIds)->get();
        if ($permissions->count() !== count($publicIds)) {
            throw new IdentityException('PERMISSION_NOT_FOUND', 422, 'One or more permissions were not found.');
        }

        return $permissions->pluck('id')->all();
    }

    private function assertUniqueName(string $tenantId, string $name, ?string $exceptPublicId = null): void
    {
        $query = Role::query()->where('tenant_id', $tenantId)->where('name', Str::slug($name));
        if ($exceptPublicId !== null) {
            $query->where('public_id', '!=', $exceptPublicId);
        }
        if ($query->exists()) {
            throw new IdentityException('ROLE_ALREADY_EXISTS', 409, 'A role with this name already exists.');
        }
    }
}
