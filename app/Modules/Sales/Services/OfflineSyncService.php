<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\BranchCatalogItem;
use App\Models\Customer;
use App\Models\DeviceSequence;
use App\Models\Invoice;
use App\Models\KdsTicket;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PrintJob;
use App\Models\ProductVariant;
use App\Models\SalesIdempotencyRecord;
use App\Models\SyncBatch;
use App\Models\SyncChangeLogEntry;
use App\Models\SyncConflict;
use App\Models\SyncInboxReceipt;
use App\Models\SyncPullCursor;
use App\Models\SyncTombstone;
use App\Models\TableSession;
use App\Modules\Sales\Data\SalesContext;
use App\Modules\Sales\Data\SyncOperation;
use App\Modules\Sales\Data\SyncOperationResult;
use App\Modules\Sales\Exceptions\SalesException;
use App\Modules\Sales\Http\Resources\SalesResource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sole coordinator for the Sales offline synchronization layer. Ingests push
 * batches from trusted branch devices with per-device monotonic-sequence
 * idempotency and all-or-nothing apply, serves a cursor-based pull feed with
 * tombstones, and manages pull-cursor acknowledgement and conflict review.
 * All tenant/branch/device scope comes from the trusted SalesContext, never
 * the payload; business logic lives in the owning Sales services.
 */
final class OfflineSyncService
{
    public function __construct(private readonly OfflineCommandDispatcher $dispatcher) {}

    /**
     * Applies a push batch. Every operation is idempotent by (device, client
     * operation id); the batch is all-or-nothing except that conflicts and
     * rejections are recorded as durable, replay-safe outcomes rather than
     * aborting sibling operations.
     *
     * @param  array<int, SyncOperation>  $operations
     * @return array{batch_id: string, results: array<int, SyncOperationResult>}
     */
    public function push(SalesContext $context, string $clientBatchId, array $operations): array
    {
        $this->assertDevice($context);
        if (! Str::isUlid($clientBatchId)) {
            throw SalesException::invalid('A valid client batch ID is required.');
        }
        $limit = (int) config('sales.sync.push_batch_limit', 200);
        if ($operations === [] || count($operations) > $limit) {
            throw SalesException::invalid('A push batch must contain between one and '.$limit.' operations.');
        }
        usort($operations, fn (SyncOperation $left, SyncOperation $right) => $left->deviceSequence <=> $right->deviceSequence);
        $existing = $this->existingBatch($context, $clientBatchId);
        if ($existing !== null) {
            if ($existing->operation_count !== count($operations)) {
                throw SalesException::conflict(SalesException::IDEMPOTENCY_CONFLICT,
                    'The client batch ID was replayed with different operations.');
            }

            return ['batch_id' => $existing->id, 'results' => $this->replayReceipts($context, $operations)];
        }

        return DB::transaction(function () use ($context, $clientBatchId, $operations): array {
            $this->assertSequenceContiguous($context, $operations);
            $batch = $this->openBatch($context, $clientBatchId, count($operations));
            $results = [];
            foreach ($operations as $operation) {
                $results[] = $this->applyOperation($context, $batch, $operation);
            }
            $this->advanceDeviceSequence($context, $operations);
            $batch->update(['state' => 'applied', 'completed_at' => now(), 'operation_count' => count($operations)]);

            return ['batch_id' => $batch->id, 'results' => $results];
        }, 3);
    }

    private function applyOperation(SalesContext $context, SyncBatch $batch, SyncOperation $operation): SyncOperationResult
    {
        $receipt = $this->existingReceipt($context, $operation->clientOperationId);
        if ($receipt !== null) {
            if ($receipt->request_fingerprint !== $operation->fingerprint) {
                throw SalesException::conflict(SalesException::IDEMPOTENCY_CONFLICT,
                    'A client operation ID was replayed with a different request.');
            }

            return new SyncOperationResult($operation->clientOperationId, $receipt->result,
                $receipt->result_code, (array) $receipt->result_body, $receipt->entity_revision);
        }
        if (! $this->dispatcher->isSafe($operation->command)) {
            return $this->reject($context, $operation);
        }
        try {
            $model = $this->dispatcher->apply($context, $operation);
        } catch (SalesException $exception) {
            if (in_array($exception->errorCode, [SalesException::STALE_VERSION,
                SalesException::TERMINAL_ORDER, SalesException::INVALID_STATE], true)) {
                return $this->recordConflict($context, $operation, $exception);
            }
            throw $exception;
        }

        return $this->recordApplied($context, $operation, $model);
    }

    private function recordApplied(SalesContext $context, SyncOperation $operation, Model $model): SyncOperationResult
    {
        $revision = $model->getAttribute('lock_version');
        $revision = is_int($revision) ? $revision : null;
        $body = ['entity_type' => $operation->entityType, 'entity_id' => $model->getKey(),
            'lock_version' => $revision];
        $this->writeReceipt($context, $operation, SyncOperationResult::APPLIED,
            $operation->command, $body, $revision);
        $this->writeIdempotency($context, $operation, $operation->command, $body, $revision);

        return SyncOperationResult::applied($operation->clientOperationId, $operation->command, $body, $revision);
    }

    private function recordConflict(SalesContext $context, SyncOperation $operation, SalesException $exception): SyncOperationResult
    {
        $serverSnapshot = $this->entitySnapshot($context, $operation->entityType, $operation->entityId);
        SyncConflict::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'device_id' => $context->deviceId,
            'entity_type' => $operation->entityType, 'entity_id' => $operation->entityId,
            'conflict_type' => $exception->errorCode, 'local_revision' => (int) ($operation->payload['expected_version'] ?? 0),
            'remote_revision' => is_int($serverSnapshot['lock_version'] ?? null) ? $serverSnapshot['lock_version'] : null,
            'local_snapshot' => $this->safeConflictPayload($operation->payload), 'remote_snapshot' => $serverSnapshot,
            'risk' => 'normal', 'state' => 'open', 'lock_version' => 0,
        ]);
        $this->writeReceipt($context, $operation, SyncOperationResult::CONFLICT, $exception->errorCode, [], null);

        return SyncOperationResult::conflict($operation->clientOperationId, $exception->errorCode);
    }

    private function reject(SalesContext $context, SyncOperation $operation): SyncOperationResult
    {
        $code = SalesException::SCOPE_VIOLATION;
        $this->writeReceipt($context, $operation, SyncOperationResult::REJECTED, $code, [], null);

        return SyncOperationResult::rejected($operation->clientOperationId, $code);
    }

    /**
     * Serves a delta page of change-log entries and tombstones after the given
     * cursor, ordered by monotonic change sequence and scoped to the trusted
     * branch. No entity bodies or sensitive fields are returned — only the
     * manifest of what changed, so the edge re-fetches authoritative state.
     *
     * @return array{entries: array<int, array<string, mixed>>, tombstones: array<int, array<string, mixed>>, cursor: int, has_more: bool}
     */
    public function pull(SalesContext $context, string $stream, int $afterSequence, ?int $limit = null): array
    {
        $this->assertDevice($context);
        if ($afterSequence < 0) {
            throw SalesException::invalid('The pull cursor must not be negative.');
        }
        $this->assertCursorRetained($context, $afterSequence);
        $max = (int) config('sales.sync.pull_page_limit', 200);
        $limit = min($limit ?? (int) config('sales.sync.pull_page_default', 100), $max);
        $limit = max($limit, 1);
        $entries = SyncChangeLogEntry::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where(fn ($q) => $q->where('branch_id', $context->branchId)->orWhereNull('branch_id'))
            ->where('change_sequence', '>', $afterSequence)
            ->orderBy('change_sequence')->limit($limit + 1)->get();
        $hasMore = $entries->count() > $limit;
        $entries = $entries->take($limit);
        $cursor = $entries->max('change_sequence') ?? $afterSequence;
        $tombstones = SyncTombstone::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where(fn ($q) => $q->where('branch_id', $context->branchId)->orWhereNull('branch_id'))
            ->where('change_sequence', '>', $afterSequence)->where('change_sequence', '<=', $cursor)
            ->orderBy('change_sequence')->get();

        return [
            'entries' => $entries->map(fn (SyncChangeLogEntry $e) => ['change_sequence' => $e->change_sequence,
                'entity_type' => $e->entity_type, 'entity_id' => $e->entity_id, 'entity_revision' => $e->entity_revision,
                'operation' => $e->operation, 'snapshot' => $this->entitySnapshot($context, $e->entity_type, $e->entity_id),
                'occurred_at' => $e->occurred_at?->toISOString()])->values()->all(),
            'tombstones' => $tombstones->map(fn (SyncTombstone $t) => ['change_sequence' => $t->change_sequence,
                'entity_type' => $t->entity_type, 'entity_id' => $t->entity_id,
                'deletion_revision' => $t->deletion_revision, 'deleted_at' => $t->deleted_at?->toISOString()])->values()->all(),
            'cursor' => (int) $cursor,
            'has_more' => $hasMore,
            'server_time' => now()->toISOString(),
        ];
    }

    /**
     * Records the edge's acknowledged high-water mark for a pull stream.
     * Regression (a lower sequence than stored) is only accepted when the
     * caller explicitly requests a resync, guarding against silent rewinds.
     */
    public function acknowledgePull(SalesContext $context, string $stream, int $sequence, bool $resync = false): SyncPullCursor
    {
        $this->assertDevice($context);
        if ($sequence < 0 || $stream === '') {
            throw SalesException::invalid('A valid pull stream and non-negative sequence are required.');
        }

        return DB::transaction(function () use ($context, $stream, $sequence, $resync): SyncPullCursor {
            $cursor = SyncPullCursor::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('device_id', $context->deviceId)
                ->where('stream', $stream)->lockForUpdate()->first();
            if ($cursor === null) {
                return SyncPullCursor::withoutGlobalScopes()->create([
                    'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'device_id' => $context->deviceId,
                    'stream' => $stream, 'last_sequence' => $sequence, 'last_revision' => 0, 'state' => 'active',
                    'last_pulled_at' => now(), 'last_applied_at' => now(), 'lock_version' => 0,
                ]);
            }
            if ($sequence < $cursor->last_sequence && ! $resync) {
                throw SalesException::conflict(SalesException::STALE_VERSION,
                    'The acknowledged cursor is behind the recorded position; request a resync to rewind.');
            }
            $cursor->update(['last_sequence' => $sequence, 'state' => $resync ? 'resyncing' : 'active',
                'last_applied_at' => now(), 'lock_version' => $cursor->lock_version + 1]);

            return $cursor->refresh();
        }, 3);
    }

    /**
     * Lists open conflicts quarantined for this branch for manual review.
     *
     * @return LengthAwarePaginator<int, SyncConflict>
     */
    public function conflicts(SalesContext $context, int $perPage)
    {
        return SyncConflict::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('state', 'open')
            ->latest()->paginate($perPage);
    }

    /**
     * Resolves a quarantined conflict. Resolution is metadata-only (the edge
     * re-submits corrected operations); this records the human decision under
     * optimistic locking and never mutates the underlying aggregate here.
     */
    public function resolveConflict(SalesContext $context, string $conflictId, string $resolution, ?string $reason): SyncConflict
    {
        if (! in_array($resolution, ['accept_remote', 'keep_local', 'discard'], true)) {
            throw SalesException::invalid('An unknown conflict resolution was requested.');
        }

        return DB::transaction(function () use ($context, $conflictId, $resolution, $reason): SyncConflict {
            $conflict = SyncConflict::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->whereKey($conflictId)->lockForUpdate()->first()
                ?? throw new SalesException(SalesException::NOT_FOUND, 404, 'The conflict was not found in this branch.');
            if ($conflict->state !== 'open') {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'The conflict has already been resolved.');
            }
            $conflict->update(['state' => 'resolved', 'resolution' => $resolution, 'resolution_reason' => $reason,
                'resolved_by' => $context->userId, 'resolved_at' => now(), 'lock_version' => $conflict->lock_version + 1]);

            return $conflict->refresh();
        }, 3);
    }

    private function assertDevice(SalesContext $context): void
    {
        if ($context->deviceId === null) {
            throw new SalesException(SalesException::SCOPE_VIOLATION, 403,
                'Offline synchronization requires a trusted branch device.');
        }
    }

    /** @param array<int, SyncOperation> $operations */
    private function assertSequenceContiguous(SalesContext $context, array $operations): void
    {
        $sequences = array_map(fn (SyncOperation $o) => $o->deviceSequence, $operations);
        if (count(array_unique($sequences)) !== count($sequences) || min($sequences) < 1) {
            throw SalesException::invalid('Operation device sequences must be unique and positive.');
        }
        sort($sequences);
        $deviceSequence = DeviceSequence::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('device_id', $context->deviceId)
            ->lockForUpdate()->first();
        $expected = $deviceSequence?->next_sequence ?? 1;
        foreach ($sequences as $index => $sequence) {
            if ($sequence !== $expected + $index) {
                throw SalesException::conflict(SalesException::INVALID_STATE,
                    'A device sequence gap was detected; resend from the expected sequence.',
                    ['expected_sequence' => $expected]);
            }
        }
    }

    private function existingBatch(SalesContext $context, string $clientBatchId): ?SyncBatch
    {
        return SyncBatch::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('device_id', $context->deviceId)
            ->where('client_batch_id', $clientBatchId)->first();
    }

    private function openBatch(SalesContext $context, string $clientBatchId, int $count): SyncBatch
    {
        return SyncBatch::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'device_id' => $context->deviceId,
            'client_batch_id' => $clientBatchId, 'direction' => 'push',
            'schema_version' => (int) config('sales.sync.schema_version', 1), 'operation_count' => $count,
            'state' => 'processing', 'started_at' => now(), 'lock_version' => 0,
        ]);
    }

    private function existingReceipt(SalesContext $context, string $operationId): ?SyncInboxReceipt
    {
        return SyncInboxReceipt::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('device_id', $context->deviceId)->where('operation_id', $operationId)->first();
    }

    /**
     * @param  array<int, SyncOperation>  $operations
     * @return array<int, SyncOperationResult>
     */
    private function replayReceipts(SalesContext $context, array $operations): array
    {
        return array_map(function (SyncOperation $operation) use ($context): SyncOperationResult {
            $receipt = $this->existingReceipt($context, $operation->clientOperationId);
            if ($receipt === null || $receipt->request_fingerprint !== $operation->fingerprint) {
                throw SalesException::conflict(SalesException::IDEMPOTENCY_CONFLICT,
                    'The client batch ID was replayed with different operations.');
            }

            return new SyncOperationResult($operation->clientOperationId, SyncOperationResult::DUPLICATE,
                $receipt->result_code, (array) $receipt->result_body, $receipt->entity_revision);
        }, $operations);
    }

    /** @param array<string, mixed> $body */
    private function writeReceipt(SalesContext $context, SyncOperation $operation, string $result, string $code, array $body, ?int $revision): void
    {
        SyncInboxReceipt::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'device_id' => $context->deviceId,
            'operation_id' => $operation->clientOperationId, 'request_fingerprint' => $operation->fingerprint,
            'result' => $result, 'result_code' => $code, 'result_body' => $body,
            'entity_revision' => $revision, 'applied_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $body */
    private function writeIdempotency(SalesContext $context, SyncOperation $operation, string $code, array $body, ?int $revision): void
    {
        $days = (int) config('sales.sync.idempotency_retention_days', 30);
        SalesIdempotencyRecord::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'device_id' => $context->deviceId,
            'idempotency_key' => $operation->clientOperationId, 'request_fingerprint' => $operation->fingerprint,
            'operation_type' => $operation->command, 'result_code' => $code, 'result_body' => $body,
            'entity_type' => $operation->entityType, 'entity_id' => $body['entity_id'] ?? null,
            'expires_at' => now()->addDays($days), 'created_at' => now(),
        ]);
    }

    /** @param array<int, SyncOperation> $operations */
    private function advanceDeviceSequence(SalesContext $context, array $operations): void
    {
        $highest = max(array_map(fn (SyncOperation $o) => $o->deviceSequence, $operations));
        $highestClock = max(array_map(fn (SyncOperation $o) => $o->logicalClock, $operations));
        $sequence = DeviceSequence::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('device_id', $context->deviceId)->lockForUpdate()->first();
        if ($sequence === null) {
            DeviceSequence::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'device_id' => $context->deviceId,
                'next_sequence' => $highest + 1, 'logical_clock' => $highestClock,
                'last_acknowledged_sequence' => $highest, 'lock_version' => 0,
            ]);

            return;
        }
        $sequence->update(['next_sequence' => $highest + 1, 'last_acknowledged_sequence' => $highest,
            'logical_clock' => max($sequence->logical_clock, $highestClock), 'lock_version' => $sequence->lock_version + 1]);
    }

    private function assertCursorRetained(SalesContext $context, int $afterSequence): void
    {
        if ($afterSequence === 0) {
            return;
        }
        $earliestChange = SyncChangeLogEntry::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->min('change_sequence');
        $earliestTombstone = SyncTombstone::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->min('change_sequence');
        $retained = array_values(array_filter([$earliestChange, $earliestTombstone], fn ($value) => $value !== null));
        $earliest = $retained === [] ? null : min($retained);
        if ($earliest !== null && $afterSequence < (int) $earliest - 1) {
            $current = (int) SyncChangeLogEntry::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->max('change_sequence');
            throw SalesException::conflict(SalesException::RESYNC_REQUIRED,
                'The requested cursor is outside the retained synchronization window.',
                ['minimum_cursor' => (int) $earliest - 1, 'current_cursor' => $current]);
        }
    }

    /** @return array<string, mixed>|null */
    private function entitySnapshot(SalesContext $context, string $type, string $id): ?array
    {
        $branch = fn ($query) => $query->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($id)->first();

        return match ($type) {
            'order' => ($model = $branch(Order::withoutGlobalScopes())) === null ? null : SalesResource::order($model),
            'order_item' => ($model = $branch(OrderItem::withoutGlobalScopes())) === null ? null : $model->only([
                'id', 'order_id', 'line_number', 'product_variant_id', 'product_name', 'variant_name', 'sku',
                'quantity', 'unit_price_amount', 'gross_amount', 'discount_amount', 'tax_amount', 'net_amount',
                'currency', 'state', 'course_number', 'seat_number', 'notes',
            ]),
            'table_session' => ($model = $branch(TableSession::withoutGlobalScopes())) === null ? null : $model->only([
                'id', 'dining_table_id', 'guest_count', 'state', 'opened_at', 'closed_at', 'lock_version',
            ]),
            'kds_ticket' => ($model = $branch(KdsTicket::withoutGlobalScopes())) === null ? null : SalesResource::ticket($model),
            'print_job' => ($model = $branch(PrintJob::withoutGlobalScopes())) === null ? null : $model->only([
                'id', 'payload_type', 'document_type', 'document_id', 'state', 'attempt_count', 'available_at',
                'printed_at', 'failed_at', 'lock_version',
            ]),
            'payment' => ($model = $branch(Payment::withoutGlobalScopes())) === null ? null : SalesResource::payment($model),
            'invoice' => ($model = $branch(Invoice::withoutGlobalScopes())) === null ? null : SalesResource::invoice($model),
            'customer' => ($model = $branch(Customer::withoutGlobalScopes())) === null ? null : SalesResource::customer($model),
            'product_variant' => $this->catalogSnapshot($context, $id),
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    private function catalogSnapshot(SalesContext $context, string $id): ?array
    {
        $listed = BranchCatalogItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('product_variant_id', $id)->where('status', 'active')->exists();
        $variant = $listed ? ProductVariant::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->with('product')->whereKey($id)->first() : null;

        return $variant === null ? null : ['id' => $variant->id, 'product_id' => $variant->product_id,
            'name' => $variant->product?->name, 'variant_name' => $variant->name,
            'sku' => $variant->product?->sku, 'barcode' => $variant->barcode, 'status' => $variant->status];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function safeConflictPayload(array $payload): array
    {
        $forbidden = ['tenant_id', 'branch_id', 'device_id', 'actor_id', 'user_id', 'approved_by',
            'pan', 'card_number', 'cvv', 'cvc', 'password', 'token', 'provider_reference', 'provider_snapshot'];
        $clean = [];
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), $forbidden, true)) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->safeConflictPayload($value) : $value;
        }

        return $clean;
    }
}
