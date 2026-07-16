<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Contracts\IdentityRepository;
use App\Modules\Identity\Contracts\SecurityPolicyRepository;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Services\AccountLockoutService;
use App\Modules\Identity\Services\IdentifierNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class WebSessionController extends Controller
{
    public function login(
        LoginRequest $request,
        IdentityRepository $identities,
        SecurityPolicyRepository $policies,
        IdentifierNormalizer $normalizer,
        AccountLockoutService $lockout,
    ): JsonResponse {
        $tenant = $identities->findActiveTenantBySlug(mb_strtolower($request->string('tenant_slug')->toString()));
        $identifier = $normalizer->normalize($request->string('identifier')->toString());
        $user = $tenant === null ? null : $identities->findActiveUser($tenant, $identifier->column, $identifier->value);
        if (! $user instanceof User) {
            Hash::check($request->string('password')->toString(), $this->dummyHash());
            throw $this->invalidCredentials();
        }

        $policy = $policies->forTenant($tenant->getKey());
        $lockout->assertAvailable($user);
        if (! Hash::check($request->string('password')->toString(), $user->password)) {
            $lockout->recordFailure($user, $policy);
            throw $this->invalidCredentials();
        }
        if ($policy->mfa_required || $user->two_factor_enabled) {
            throw new IdentityException('MFA_REQUIRED', 403, 'Web login is unavailable for accounts that require MFA.');
        }

        $lockout->recordSuccess($user);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['data' => ['authenticated' => true, 'user_id' => $user->public_id]]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['authenticated' => false]]);
    }

    private function invalidCredentials(): IdentityException
    {
        return new IdentityException('INVALID_CREDENTIALS', 401, 'The supplied credentials are invalid.');
    }

    private function dummyHash(): string
    {
        static $hash;

        return $hash ??= Hash::make('identity-invalid-credential-sentinel');
    }
}
