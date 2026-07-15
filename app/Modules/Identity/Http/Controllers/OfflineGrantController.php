<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\OfflineLoginGrant;
use App\Modules\Identity\Contracts\IdentityRepository;
use App\Modules\Identity\Contracts\SecurityPolicyRepository;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Http\IdentityResponse;
use App\Modules\Identity\Http\Requests\IndexRequest;
use App\Modules\Identity\Http\Requests\OfflineGrantRequest;
use App\Modules\Identity\Http\Requests\ReceiptRequest;
use App\Modules\Identity\Http\Requests\RevokeRequest;
use App\Modules\Identity\Services\OfflineLoginService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfflineGrantController extends Controller
{
    public function issue(
        OfflineGrantRequest $request,
        IdentityRepository $identities,
        SecurityPolicyRepository $policies,
        OfflineLoginService $offline,
    ): JsonResponse {
        $user = $request->user('api');
        $branch = $identities->findGrantedBranch($user, $request->string('branch_id')->toString());
        $device = Device::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)
            ->find($request->string('device_id')->toString());
        if ($branch === null || $device === null) {
            throw new IdentityException('OFFLINE_LOGIN_NOT_ALLOWED', 403, 'Offline login is not allowed for this account and device.');
        }

        $token = $offline->issue($user, $branch, $device, $policies->forTenant($user->tenant_id));
        $grant = OfflineLoginGrant::withoutGlobalScopes()->findOrFail($token->id);

        return response()->json(['data' => array_merge(IdentityResponse::offlineGrant($grant), [
            'grant_token' => $token->value,
        ])], 201);
    }

    public function index(IndexRequest $request): JsonResponse
    {
        $user = $request->user('api');
        $paginator = OfflineLoginGrant::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->getKey())->latest('issued_at')->paginate($request->perPage());

        return response()->json(['data' => collect($paginator->items())->map(IdentityResponse::offlineGrant(...)),
            'meta' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(),
                'total' => $paginator->total(), 'last_page' => $paginator->lastPage()]]);
    }

    public function revoke(
        string $grant,
        RevokeRequest $request,
        OfflineLoginService $offline,
    ): JsonResponse {
        $model = $this->grant($grant, $request);
        $offline->revoke($request->user('api'), $model, $request->validated('reason', 'user_revoked'));

        return response()->json(['data' => ['revoked' => true]]);
    }

    public function receipt(
        string $grant,
        ReceiptRequest $request,
        OfflineLoginService $offline,
    ): JsonResponse {
        $receipt = $offline->ingestReceipt(
            $this->grant($grant, $request),
            $request->string('client_receipt_id')->toString(),
            $request->string('result')->toString(),
            CarbonImmutable::parse($request->string('occurred_at')->toString()),
            $request->validated('metadata', []),
            $request->ip(),
        );

        return response()->json(['data' => [
            'id' => $receipt->getKey(),
            'grant_id' => $receipt->offline_login_grant_id,
            'client_receipt_id' => $receipt->client_receipt_id,
            'result' => $receipt->result,
            'occurred_at' => $receipt->occurred_at->toIso8601String(),
            'synced_at' => $receipt->synced_at?->toIso8601String(),
        ]], 201);
    }

    private function grant(string $id, Request $request): OfflineLoginGrant
    {
        $user = $request->user('api');

        return OfflineLoginGrant::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->getKey())->find($id)
            ?? throw new IdentityException('OFFLINE_GRANT_NOT_FOUND', 404, 'The offline grant was not found.');
    }
}
