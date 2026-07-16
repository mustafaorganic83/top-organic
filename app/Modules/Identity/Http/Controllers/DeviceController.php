<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Tenant;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Http\IdentityResponse;
use App\Modules\Identity\Http\Requests\IndexRequest;
use App\Modules\Identity\Http\Requests\RegisterDeviceRequest;
use App\Modules\Identity\Http\Requests\RevokeRequest;
use App\Modules\Identity\Services\DeviceAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function register(RegisterDeviceRequest $request, DeviceAuthorizationService $devices): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', mb_strtolower($request->string('tenant_slug')->toString()))
            ->where('is_active', true)->first();
        if ($tenant === null) {
            throw new IdentityException('DEVICE_REGISTRATION_INVALID', 422, 'The device registration could not be accepted.');
        }

        $attributes = $request->safe()->except('tenant_slug');
        if (($attributes['branch_id'] ?? null) !== null && ! Branch::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())->where('is_active', true)->whereKey($attributes['branch_id'])->exists()) {
            throw new IdentityException('DEVICE_REGISTRATION_INVALID', 422, 'The device registration could not be accepted.');
        }

        $duplicate = Device::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())
            ->where(fn ($query) => $query->where('code', $attributes['code'])
                ->orWhere('key_fingerprint', mb_strtolower($attributes['key_fingerprint'])))->exists();
        if ($duplicate) {
            throw new IdentityException('DEVICE_ALREADY_REGISTERED', 409, 'The device is already registered.');
        }

        $device = $devices->register($tenant, $attributes);

        return response()->json(['data' => IdentityResponse::device($device)], 201);
    }

    public function index(IndexRequest $request): JsonResponse
    {
        $actor = $request->user('api');
        $query = Device::withoutGlobalScopes()->where('tenant_id', $actor->tenant_id);
        if (! $actor->hasPermissionTo('identity.devices.view')) {
            $branchId = $request->attributes->get('auth_session')?->branch_id;
            if ($branchId === null || ($request->filled('branch_id') && $request->validated('branch_id') !== $branchId)) {
                throw new IdentityException('BRANCH_SCOPE_VIOLATION', 403, 'The device query is outside the actor branch scope.');
            }
            $query->where('branch_id', $branchId);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->validated('branch_id'));
        }
        $paginator = $query->latest()->paginate($request->perPage());

        return response()->json(['data' => collect($paginator->items())->map(IdentityResponse::device(...)),
            'meta' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(),
                'total' => $paginator->total(), 'last_page' => $paginator->lastPage()]]);
    }

    public function show(string $device, Request $request): JsonResponse
    {
        return response()->json(['data' => IdentityResponse::device(
            $this->device($device, $request, 'identity.devices.view'),
        )]);
    }

    public function approve(
        string $device,
        Request $request,
        DeviceAuthorizationService $devices,
    ): JsonResponse {
        $approved = $devices->approve(
            $this->device($device, $request, 'identity.devices.manage'),
            $request->user('api'),
        );

        return response()->json(['data' => IdentityResponse::device($approved)]);
    }

    public function revoke(
        string $device,
        RevokeRequest $request,
        DeviceAuthorizationService $devices,
    ): JsonResponse {
        $revoked = $devices->revoke(
            $this->device($device, $request, 'identity.devices.manage'),
            $request->user('api'),
            $request->validated('reason', 'administrator_revoked'),
        );

        return response()->json(['data' => IdentityResponse::device($revoked)]);
    }

    private function device(string $id, Request $request, string $permission): Device
    {
        $actor = $request->user('api');
        $device = Device::withoutGlobalScopes()->where('tenant_id', $actor->tenant_id)->find($id)
            ?? throw new IdentityException('DEVICE_NOT_FOUND', 404, 'The device was not found.');
        $branchId = $request->attributes->get('auth_session')?->branch_id;
        if ($device->branch_id !== $branchId && ! $actor->hasPermissionTo($permission)) {
            throw new IdentityException('BRANCH_SCOPE_VIOLATION', 403, 'The device is outside the actor branch scope.');
        }

        return $device;
    }
}
