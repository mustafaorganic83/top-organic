<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationWaitlistEntry extends BranchScopedModel
{
    protected $table = 'reservation_waitlist_entries';

    protected function casts(): array
    {
        return [
            'party_size' => 'integer',
            'position' => 'integer',
            'quoted_wait_minutes' => 'integer',
            'joined_at' => 'immutable_datetime',
            'notified_at' => 'immutable_datetime',
            'seated_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }
}
