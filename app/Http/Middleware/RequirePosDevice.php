<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Support\Context\AppContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequirePosDevice
{
    public function __construct(private readonly AppContext $context) {}

    public function handle(Request $request, Closure $next, string $profile = 'pos'): Response
    {
        $tenantId = $this->context->tenantId();
        $branchId = $this->context->branchId();
        $deviceId = $this->context->deviceId();
        if ($tenantId === null || $branchId === null || $deviceId === null) {
            throw new IdentityException('POS_DEVICE_REQUIRED', 403, 'An authorized branch device is required.');
        }

        $allowed = (array) config($profile === 'edge' ? 'sales.edge_print_device_types' : 'sales.pos_device_types', []);
        $device = Device::withoutGlobalScopes()->whereKey($deviceId)
            ->where('tenant_id', $tenantId)->where('branch_id', $branchId)->first();
        if ($device === null || $device->status !== 'authorized' || $device->revoked_at !== null
            || ! in_array($device->type, $allowed, true)) {
            throw new IdentityException('DEVICE_NOT_AUTHORIZED', 403, 'The trusted device is not authorized for this operation.');
        }

        $request->attributes->set('sales_device', $device);

        return $next($request);
    }
}
