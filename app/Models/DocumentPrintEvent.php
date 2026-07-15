<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentPrintEvent extends BranchScopedModel
{
    use Immutable;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['copy_number' => 'integer', 'occurred_at' => 'immutable_datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
