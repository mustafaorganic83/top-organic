<?php

namespace App\Models;

class DomainOutboxEvent extends TenantScopedModel
{
    protected function casts(): array
    {
        return ['aggregate_sequence' => 'integer', 'event_version' => 'integer', 'payload' => 'array', 'attempt_count' => 'integer', 'available_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }
}
