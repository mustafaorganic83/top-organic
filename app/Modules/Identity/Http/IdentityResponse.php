<?php

namespace App\Modules\Identity\Http;

use App\Models\AuthSession;
use App\Models\Device;
use App\Models\OfflineLoginGrant;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Identity\Data\TokenPair;

final class IdentityResponse
{
    public static function tokens(TokenPair $tokens): array
    {
        return [
            'token_type' => $tokens->tokenType,
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'access_token_expires_at' => $tokens->accessTokenExpiresAt->toIso8601String(),
            'refresh_token_expires_at' => $tokens->refreshTokenExpiresAt->toIso8601String(),
            'session_id' => $tokens->authSessionId,
        ];
    }

    public static function user(User $user, AuthSession $session, array $permissions): array
    {
        return [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'employee_code' => $user->employee_code,
            'preferred_locale' => $user->preferred_locale,
            'tenant_id' => $user->tenant_id,
            'branch_id' => $session->branch_id,
            'device_id' => $session->device_id,
            'permissions' => array_values($permissions),
        ];
    }

    public static function session(AuthSession $session): array
    {
        return [
            'id' => $session->getKey(),
            'branch_id' => $session->branch_id,
            'device_id' => $session->device_id,
            'authentication_method' => $session->authentication_method,
            'ip_address' => $session->ip_address,
            'user_agent' => $session->user_agent,
            'last_seen_at' => $session->last_seen_at?->toIso8601String(),
            'expires_at' => $session->expires_at->toIso8601String(),
            'created_at' => $session->created_at?->toIso8601String(),
        ];
    }

    public static function device(Device $device): array
    {
        return [
            'id' => $device->getKey(),
            'branch_id' => $device->branch_id,
            'code' => $device->code,
            'name' => $device->name,
            'type' => $device->type,
            'status' => $device->status,
            'app_version' => $device->app_version,
            'os_version' => $device->os_version,
            'authorization_requested_at' => $device->authorization_requested_at?->toIso8601String(),
            'authorized_at' => $device->authorized_at?->toIso8601String(),
            'revoked_at' => $device->revoked_at?->toIso8601String(),
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
        ];
    }

    public static function role(Role $role): array
    {
        return [
            'id' => $role->public_id,
            'name' => $role->name,
            'label' => $role->label,
            'description' => $role->description,
            'status' => $role->status,
            'permissions' => $role->relationLoaded('permissions')
                ? $role->permissions->map(fn (Permission $permission) => self::permission($permission))->values()->all()
                : [],
            'created_at' => $role->created_at?->toIso8601String(),
        ];
    }

    public static function permission(Permission $permission): array
    {
        return [
            'id' => $permission->public_id,
            'name' => $permission->name,
            'label' => $permission->label,
            'description' => $permission->description,
            'risk_level' => $permission->risk_level,
        ];
    }

    public static function offlineGrant(OfflineLoginGrant $grant): array
    {
        return [
            'id' => $grant->getKey(),
            'branch_id' => $grant->branch_id,
            'device_id' => $grant->device_id,
            'issued_at' => $grant->issued_at->toIso8601String(),
            'expires_at' => $grant->expires_at->toIso8601String(),
            'last_used_at' => $grant->last_used_at?->toIso8601String(),
            'revoked_at' => $grant->revoked_at?->toIso8601String(),
        ];
    }
}
