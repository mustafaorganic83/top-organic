<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TenantSecurityPolicy extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'max_failed_login_attempts', 'lockout_minutes',
        'password_min_length', 'password_history_count', 'access_token_ttl_minutes',
        'refresh_token_ttl_minutes', 'remember_device_days', 'offline_login_hours',
        'mfa_required', 'allow_remembered_devices', 'allow_offline_login',
    ];

    protected function casts(): array
    {
        return [
            'max_failed_login_attempts' => 'integer',
            'lockout_minutes' => 'integer',
            'password_min_length' => 'integer',
            'password_history_count' => 'integer',
            'access_token_ttl_minutes' => 'integer',
            'refresh_token_ttl_minutes' => 'integer',
            'remember_device_days' => 'integer',
            'offline_login_hours' => 'integer',
            'mfa_required' => 'boolean',
            'allow_remembered_devices' => 'boolean',
            'allow_offline_login' => 'boolean',
        ];
    }
}
