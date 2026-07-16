<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserBranchRole;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Http\IdentityResponse;
use App\Modules\Identity\Http\Requests\IndexRequest;
use App\Modules\Identity\Http\Requests\RevokeRequest;
use App\Modules\Identity\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthorizationController extends Controller
{
    public function catalog(): JsonResponse
    {
        $groups = PermissionGroup::query()->where('is_active', true)->with(['permissions' => fn ($query) => $query->orderBy('name')])
            ->orderBy('display_order')->get()->map(fn (PermissionGroup $group) => [
                'id' => $group->getKey(),
                'code' => $group->code,
                'name' => $group->name,
                'description' => $group->description,
                'permissions' => $group->permissions->map(IdentityResponse::permission(...))->values()->all(),
            ]);

        return response()->json(['data' => $groups]);
    }

    public function permissions(IndexRequest $request): JsonResponse
    {
        $paginator = Permission::query()->with('group')->orderBy('name')->paginate($request->perPage());
        $data = collect($paginator->items())->map(fn (Permission $permission) => array_merge(
            IdentityResponse::permission($permission),
            ['group_id' => $permission->permission_group_id],
        ));

        return response()->json(['data' => $data,
            'meta' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(),
                'total' => $paginator->total(), 'last_page' => $paginator->lastPage()]]);
    }

    public function grant(
        string $user,
        string $branch,
        string $role,
        Request $request,
        RoleService $roles,
    ): JsonResponse {
        $actor = $request->user('api');
        $tenant = Tenant::query()->findOrFail($actor->tenant_id);
        $target = User::withoutGlobalScopes()->where('tenant_id', $actor->tenant_id)
            ->where('public_id', $user)->first();
        $branchModel = Branch::withoutGlobalScopes()->where('tenant_id', $actor->tenant_id)->find($branch);
        $roleModel = Role::query()->where('tenant_id', $actor->tenant_id)->where('public_id', $role)->first();
        if ($target === null || $branchModel === null || $roleModel === null) {
            throw new IdentityException('ROLE_GRANT_TARGET_NOT_FOUND', 404, 'The role grant target was not found.');
        }
        $this->assertBranchScope($actor, $request, $branchModel->getKey());
        $grant = $roles->grant($tenant, $branchModel, $target, $roleModel, $actor);

        return response()->json(['data' => [
            'id' => $grant->getKey(),
            'user_id' => $target->public_id,
            'branch_id' => $branchModel->getKey(),
            'role_id' => $roleModel->public_id,
            'effective_at' => $grant->effective_at->toIso8601String(),
        ]], 201);
    }

    public function revoke(string $grant, RevokeRequest $request, RoleService $roles): JsonResponse
    {
        $model = UserBranchRole::withoutGlobalScopes()->where('tenant_id', $request->user('api')->tenant_id)
            ->whereNull('revoked_at')->find($grant)
            ?? throw new IdentityException('ROLE_GRANT_NOT_FOUND', 404, 'The role grant was not found.');
        $this->assertBranchScope($request->user('api'), $request, $model->branch_id);
        $roles->revokeGrant($model, $request->user('api'), $request->validated('reason', 'administrator_revoked'));

        return response()->json(['data' => ['revoked' => true]]);
    }

    private function assertBranchScope(User $actor, Request $request, string $branchId): void
    {
        $session = $request->attributes->get('auth_session');
        if ($session?->branch_id !== $branchId && ! $actor->hasPermissionTo('identity.roles.assign')) {
            throw new IdentityException(
                'BRANCH_SCOPE_VIOLATION',
                403,
                'The role grant is outside the actor branch scope.',
            );
        }
    }
}
