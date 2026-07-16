<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use App\Models\SyncConflict;
use App\Models\SyncPullCursor;
use App\Modules\Sales\Data\SyncOperationResult;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Serializes offline-sync responses. Deliberately narrow: only non-sensitive
 * identifiers, revisions, and outcome codes cross the wire — never payloads,
 * snapshots, or credentials.
 */
final class SyncResource
{
    /**
     * @param  array{batch_id: string, results: array<int, SyncOperationResult>}  $push
     * @return array<string, mixed>
     */
    public static function push(array $push): array
    {
        return [
            'batch_id' => $push['batch_id'],
            'results' => array_map(fn (SyncOperationResult $r) => $r->toArray(), $push['results']),
        ];
    }

    public static function cursor(SyncPullCursor $cursor): array
    {
        return ['stream' => $cursor->stream, 'last_sequence' => $cursor->last_sequence,
            'state' => $cursor->state, 'last_applied_at' => $cursor->last_applied_at?->toISOString()];
    }

    public static function conflict(SyncConflict $conflict): array
    {
        return ['id' => $conflict->id, 'entity_type' => $conflict->entity_type, 'entity_id' => $conflict->entity_id,
            'conflict_type' => $conflict->conflict_type, 'local_revision' => $conflict->local_revision,
            'remote_revision' => $conflict->remote_revision, 'risk' => $conflict->risk, 'state' => $conflict->state,
            'resolution' => $conflict->resolution, 'resolved_at' => $conflict->resolved_at?->toISOString(),
            'created_at' => $conflict->created_at?->toISOString()];
    }

    /**
     * @param  LengthAwarePaginator<int, SyncConflict>  $page
     * @return array<string, mixed>
     */
    public static function conflicts(LengthAwarePaginator $page): array
    {
        return SalesResource::paginated($page, self::conflict(...));
    }
}
