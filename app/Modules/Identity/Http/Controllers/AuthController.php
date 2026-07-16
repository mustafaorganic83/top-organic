<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuthSession;
use App\Models\Device;
use App\Modules\Identity\Contracts\SecurityPolicyRepository;
use App\Modules\Identity\Data\LoginData;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Http\IdentityResponse;
use App\Modules\Identity\Http\Requests\ChangePasswordRequest;
use App\Modules\Identity\Http\Requests\IndexRequest;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\MfaRequest;
use App\Modules\Identity\Http\Requests\RefreshRequest;
use App\Modules\Identity\Services\AuthenticationService;
use App\Modules\Identity\Services\PasswordPolicyService;
use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\Identity\Services\RememberedDeviceService;
use App\Modules\Identity\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request, AuthenticationService $authentication): JsonResponse
    {
        $result = $authentication->login(new LoginData(
            $request->string('tenant_slug')->toString(),
            $request->string('identifier')->toString(),
            $request->string('password')->toString(),
            $request->validated('branch_id'),
            $request->validated('device_id'),
            $request->ip(),
            $request->userAgent(),
            $request->validated('remembered_device_token'),
        ));

        if ($result->mfaRequired) {
            return response()->json(['data' => [
                'mfa_required' => true,
                'challenge_token' => $result->mfaChallenge,
                'challenge_id' => $result->mfaChallengeId,
            ]], 202);
        }

        return response()->json(['data' => IdentityResponse::tokens($result->tokens)]);
    }

    public function completeMfa(MfaRequest $request, AuthenticationService $authentication): JsonResponse
    {
        $tokens = $authentication->completeMfa(
            $request->string('challenge_token')->toString(),
            $request->string('response')->toString(),
        );

        return response()->json(['data' => IdentityResponse::tokens($tokens)]);
    }

    public function refresh(RefreshRequest $request, AuthenticationService $authentication): JsonResponse
    {
        $tokens = $authentication->refresh($request->string('refresh_token')->toString());

        return response()->json(['data' => IdentityResponse::tokens($tokens)]);
    }

    public function me(Request $request, PermissionResolver $permissions): JsonResponse
    {
        $user = $request->user('api');
        $session = $request->attributes->get('auth_session');
        $current = $permissions->resolve($user->fresh(), $session->branch_id)->all();

        return response()->json(['data' => IdentityResponse::user($user, $session, $current)]);
    }

    public function logout(Request $request, SessionService $sessions): JsonResponse
    {
        $session = $request->attributes->get('auth_session');
        $sessions->revoke($request->user('api'), $session->getKey(), 'logout');

        return response()->json(['data' => ['requires_relogin' => true]]);
    }

    public function logoutAll(Request $request, SessionService $sessions): JsonResponse
    {
        $count = $sessions->logoutAll($request->user('api'));

        return response()->json(['data' => ['revoked_sessions' => $count, 'requires_relogin' => true]]);
    }

    public function sessions(IndexRequest $request): JsonResponse
    {
        $paginator = AuthSession::withoutGlobalScopes()->where('user_id', $request->user('api')->getKey())
            ->whereNull('revoked_at')->where('expires_at', '>', now())->latest()->paginate($request->perPage());

        return response()->json(['data' => collect($paginator->items())->map(IdentityResponse::session(...)),
            'meta' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(),
                'total' => $paginator->total(), 'last_page' => $paginator->lastPage()]]);
    }

    public function revokeSession(string $session, Request $request, SessionService $sessions): JsonResponse
    {
        $sessions->revoke($request->user('api'), $session);

        return response()->json(['data' => ['revoked' => true]]);
    }

    public function changePassword(
        ChangePasswordRequest $request,
        PasswordPolicyService $passwords,
        SecurityPolicyRepository $policies,
        SessionService $sessions,
    ): JsonResponse {
        $user = $request->user('api');
        if (! Hash::check($request->string('current_password')->toString(), $user->password)) {
            throw new IdentityException('CURRENT_PASSWORD_INVALID', 422, 'The current password is invalid.');
        }

        $passwords->change($user, $request->string('password')->toString(), $policies->forTenant($user->tenant_id));
        $count = $sessions->logoutAll($user);

        return response()->json(['data' => ['revoked_sessions' => $count, 'requires_relogin' => true]]);
    }

    public function rememberDevice(
        string $device,
        Request $request,
        RememberedDeviceService $remembered,
        SecurityPolicyRepository $policies,
    ): JsonResponse {
        $user = $request->user('api');
        $session = $request->attributes->get('auth_session');
        $model = Device::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->find($device);
        if ($model === null || $session->device_id !== $model->getKey()) {
            throw new IdentityException('DEVICE_NOT_FOUND', 404, 'The current device was not found.');
        }
        $token = $remembered->issue($user, $model, $policies->forTenant($user->tenant_id));

        return response()->json(['data' => ['device_id' => $model->getKey(), 'trust_token' => $token->value,
            'expires_at' => $token->expiresAt->toIso8601String()]], 201);
    }

    public function revokeRememberedDevice(
        string $device,
        Request $request,
        RememberedDeviceService $remembered,
    ): JsonResponse {
        $user = $request->user('api');
        $model = Device::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->find($device);
        if ($model === null) {
            throw new IdentityException('DEVICE_NOT_FOUND', 404, 'The device was not found.');
        }
        $remembered->revoke($user, $model);

        return response()->json(['data' => ['revoked' => true]]);
    }
}
