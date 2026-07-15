<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use BelongsToTenant, HasUlids, Immutable;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'branch_id', 'sequence', 'scope_key', 'category', 'action',
        'target_type', 'target_id', 'actor_type', 'actor_id', 'device_id',
        'auth_session_id', 'source', 'result', 'reason', 'before', 'after',
        'metadata', 'request_id', 'correlation_id', 'trace_id', 'idempotency_key',
        'previous_hash', 'entry_hash', 'occurred_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            $log->scope_key ??= implode(':', [$log->tenant_id, $log->branch_id ?? 'tenant']);
        });
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'before' => 'array',
            'after' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function authSession(): BelongsTo
    {
        return $this->belongsTo(AuthSession::class);
    }
}
