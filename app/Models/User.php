<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRoles;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasApiTokens, HasFactory, HasRoles, HasUlids, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'employee_code',
        'name',
        'email',
        'phone',
        'password',
        'preferred_locale',
        'account_status',
        'is_active',
        'two_factor_enabled',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_enabled' => 'boolean',
            'failed_login_attempts' => 'integer',
            'locked_at' => 'datetime',
            'lock_expires_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_version' => 'integer',
            'security_version' => 'integer',
            'authorization_version' => 'integer',
        ];
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function getJWTIdentifier(): string
    {
        return $this->public_id;
    }

    /** @return array<string, int|string|null> */
    public function getJWTCustomClaims(): array
    {
        return [
            'tenant_id' => $this->tenant_id,
            'password_version' => $this->password_version,
            'security_version' => $this->security_version,
            'authorization_version' => $this->authorization_version,
        ];
    }

    /**
     * The branches this user has been granted access to. Chain-level users
     * may hold many branches; branch-level users typically hold one
     * (architecture doc 01 FR-1 / doc 03).
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'authorized_by');
    }

    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    public function rememberedDevices(): HasMany
    {
        return $this->hasMany(RememberedDevice::class);
    }

    public function mfaMethods(): HasMany
    {
        return $this->hasMany(MfaMethod::class);
    }

    public function mfaRecoveryCodes(): HasMany
    {
        return $this->hasMany(MfaRecoveryCode::class);
    }

    public function passwordHistories(): HasMany
    {
        return $this->hasMany(PasswordHistory::class);
    }

    public function offlineLoginGrants(): HasMany
    {
        return $this->hasMany(OfflineLoginGrant::class);
    }

    public function openedShifts(): HasMany
    {
        return $this->hasMany(PosShift::class, 'opened_by');
    }

    public function capturedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'captured_by');
    }
}
