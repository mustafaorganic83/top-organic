<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineLoginReceipt extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'offline_login_grant_id', 'tenant_id', 'branch_id', 'user_id',
        'device_id', 'client_receipt_id', 'result', 'ip_address', 'metadata',
        'occurred_at', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(OfflineLoginGrant::class, 'offline_login_grant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
