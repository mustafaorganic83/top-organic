<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KdsTicketEvent extends BranchScopedModel
{
    use Immutable;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'payload' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(KdsTicket::class, 'kds_ticket_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(KdsTicketItem::class, 'kds_ticket_item_id');
    }
}
