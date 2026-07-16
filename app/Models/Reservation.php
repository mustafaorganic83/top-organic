<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reservation extends BranchScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'party_size' => 'integer',
            'duration_minutes' => 'integer',
            'is_walk_in' => 'boolean',
            'customer_snapshot' => 'array',
            'reserved_for' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'seated_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ReservationSource::class, 'reservation_source_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(ReservationAuditLog::class);
    }
}
