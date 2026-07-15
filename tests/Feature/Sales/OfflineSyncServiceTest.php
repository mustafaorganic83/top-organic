<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\DiningTable;
use App\Models\Floor;
use App\Modules\Sales\Data\SyncOperation;
use App\Modules\Sales\Data\SyncOperationResult;
use App\Modules\Sales\Exceptions\SalesException;
use App\Modules\Sales\Services\OfflineSyncService;
use App\Modules\Sales\Services\OrderService;
use Illuminate\Support\Str;

class OfflineSyncServiceTest extends SyncTestCase
{
    public function test_push_applies_safe_commands_and_replays_exactly(): void
    {
        $sync = app(OfflineSyncService::class);
        $orderId = (string) Str::ulid();
        $ops = [
            $this->op(1, 'order.create', $orderId, ['type' => 'takeaway', 'currency' => 'IQD']),
            $this->op(2, 'order.item.add', $orderId, ['expected_version' => 0, 'variant_id' => $this->variant->id, 'quantity' => '2']),
        ];
        $batchId = (string) Str::ulid();
        $first = $sync->push($this->context, $batchId, $ops);
        $this->assertSame(SyncOperationResult::APPLIED, $first['results'][0]->result);
        $this->assertSame(SyncOperationResult::APPLIED, $first['results'][1]->result);
        $createdId = $first['results'][0]->body['entity_id'];
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('device_sequences', ['device_id' => $this->device->id, 'next_sequence' => 3]);

        // Exact byte-identical replay of the whole batch is a no-op returning duplicates.
        $replay = $sync->push($this->context, $batchId, $ops);
        $this->assertSame($first['batch_id'], $replay['batch_id']);
        $this->assertSame(SyncOperationResult::DUPLICATE, $replay['results'][0]->result);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame($createdId, $replay['results'][0]->body['entity_id']);
    }

    public function test_push_orders_operations_by_device_sequence(): void
    {
        $orderId = (string) Str::ulid();
        $result = app(OfflineSyncService::class)->push($this->context, (string) Str::ulid(), [
            $this->op(2, 'order.item.add', $orderId, [
                'expected_version' => 0, 'variant_id' => $this->variant->id, 'quantity' => '1',
            ]),
            $this->op(1, 'order.create', $orderId, ['type' => 'takeaway', 'currency' => 'IQD']),
        ]);

        $this->assertSame('order.create', $result['results'][0]->resultCode);
        $this->assertSame('order.item.add', $result['results'][1]->resultCode);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_replaying_an_operation_id_with_a_different_request_conflicts(): void
    {
        $sync = app(OfflineSyncService::class);
        $orderId = (string) Str::ulid();
        $operationId = (string) Str::ulid();
        $sync->push($this->context, (string) Str::ulid(), [
            new SyncOperation($operationId, 'order', $orderId, 'order.create', 1, 0,
                ['type' => 'takeaway', 'currency' => 'IQD'], hash('sha256', 'a')),
        ]);
        $this->expectException(SalesException::class);
        $sync->push($this->context, (string) Str::ulid(), [
            new SyncOperation($operationId, 'order', $orderId, 'order.create', 2, 0,
                ['type' => 'dine_in', 'currency' => 'IQD'], hash('sha256', 'b')),
        ]);
    }

    public function test_sequence_gaps_are_rejected(): void
    {
        $sync = app(OfflineSyncService::class);
        try {
            $sync->push($this->context, (string) Str::ulid(), [
                $this->op(2, 'order.create', (string) Str::ulid(), ['type' => 'takeaway', 'currency' => 'IQD']),
            ]);
            $this->fail('Expected a sequence gap rejection.');
        } catch (SalesException $exception) {
            $this->assertSame(SalesException::INVALID_STATE, $exception->errorCode);
        }
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_unsafe_commands_are_rejected_without_side_effects(): void
    {
        $sync = app(OfflineSyncService::class);
        $result = $sync->push($this->context, (string) Str::ulid(), [
            $this->op(1, 'gift_card.redeem', (string) Str::ulid(), ['amount' => 100]),
        ]);
        $this->assertSame(SyncOperationResult::REJECTED, $result['results'][0]->result);
        $this->assertSame(SalesException::SCOPE_VIOLATION, $result['results'][0]->resultCode);
        $this->assertDatabaseCount('gift_card_transactions', 0);
    }

    public function test_stale_version_is_quarantined_as_a_conflict(): void
    {
        $orders = app(OrderService::class);
        $order = $orders->create($this->context, ['type' => 'takeaway', 'currency' => 'IQD',
            'client_operation_id' => (string) Str::ulid()]);
        $orders->addItem($this->context, $order->id, 0, $this->variant->id, '1', [], 'pos', (string) Str::ulid());

        $sync = app(OfflineSyncService::class);
        $result = $sync->push($this->context, (string) Str::ulid(), [
            $this->op(1, 'order.item.add', $order->id, ['expected_version' => 0,
                'variant_id' => $this->variant->id, 'quantity' => '1']),
        ]);
        $this->assertSame(SyncOperationResult::CONFLICT, $result['results'][0]->result);
        $this->assertSame(SalesException::STALE_VERSION, $result['results'][0]->resultCode);
        $this->assertDatabaseHas('sync_conflicts', ['entity_id' => $order->id, 'state' => 'open',
            'conflict_type' => SalesException::STALE_VERSION]);
    }

    public function test_table_sessions_can_be_opened_and_closed_offline(): void
    {
        $floor = Floor::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'code' => 'SYNC', 'name' => 'Sync Floor']);
        $table = DiningTable::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'floor_id' => $floor->id, 'code' => 'S1', 'name' => 'Sync Table', 'capacity' => 4]);
        $sync = app(OfflineSyncService::class);
        $opened = $sync->push($this->context, (string) Str::ulid(), [
            $this->op(1, 'pos.table.open', (string) Str::ulid(), ['table_id' => $table->id, 'guest_count' => 2]),
        ]);
        $sessionId = $opened['results'][0]->body['entity_id'];
        $closed = $sync->push($this->context, (string) Str::ulid(), [
            $this->op(2, 'pos.table.close', $sessionId, ['expected_version' => 0]),
        ]);

        $this->assertSame(SyncOperationResult::APPLIED, $closed['results'][0]->result);
        $this->assertDatabaseHas('table_sessions', ['id' => $sessionId, 'state' => 'closed']);
    }

    /** @param array<string, mixed> $payload */
    private function op(int $sequence, string $command, string $entityId, array $payload): SyncOperation
    {
        return SyncOperation::fromArray([
            'client_operation_id' => (string) Str::ulid(), 'entity_type' => explode('.', $command)[0],
            'entity_id' => $entityId, 'command' => $command, 'device_sequence' => $sequence,
            'logical_clock' => $sequence, 'payload' => $payload,
        ]);
    }
}
