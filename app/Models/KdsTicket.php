<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KdsTicket extends BranchScopedModel
{
    protected function casts(): array
    {
        return ['priority' => 'integer', 'is_priority' => 'boolean', 'last_sequence' => 'integer',
            'started_at' => 'immutable_datetime', 'ready_at' => 'immutable_datetime', 'cleared_at' => 'immutable_datetime',
            'assigned_at' => 'immutable_datetime', 'served_at' => 'immutable_datetime',
            'sla_seconds' => 'integer', 'prep_seconds' => 'integer', 'lock_version' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function chef(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chef_id');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(KdsStation::class, 'kds_station_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(KdsTicketItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(KdsTicketEvent::class)->orderBy('sequence');
    }
}
