<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KdsTicketItem extends BranchScopedModel
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'preparation_snapshot' => 'array', 'prep_seconds' => 'integer',
            'started_at' => 'immutable_datetime', 'ready_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(KdsTicket::class, 'kds_ticket_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
