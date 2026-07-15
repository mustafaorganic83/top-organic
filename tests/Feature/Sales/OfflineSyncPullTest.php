<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\SyncChangeLogEntry;
use App\Models\SyncTombstone;
use App\Modules\Sales\Exceptions\SalesException;
use App\Modules\Sales\Services\OfflineSyncService;
use App\Modules\Sales\Services\OrderService;
use Illuminate\Support\Str;

class OfflineSyncPullTest extends SyncTestCase
{
    public function test_pull_returns_ordered_paginated_deltas_with_tombstones(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->changeEntry($i, $this->branch->id);
        }
        $this->tombstone(3, $this->branch->id);

        $sync = app(OfflineSyncService::class);
        $first = $sync->pull($this->context, 'default', 0, 2);
        $this->assertCount(2, $first['entries']);
        $this->assertSame([1, 2], array_column($first['entries'], 'change_sequence'));
        $this->assertTrue($first['has_more']);
        $this->assertSame(2, $first['cursor']);

        $second = $sync->pull($this->context, 'default', $first['cursor'], 2);
        $this->assertSame([3, 4], array_column($second['entries'], 'change_sequence'));
        $this->assertCount(1, $second['tombstones']);
        $this->assertSame(3, $second['tombstones'][0]['change_sequence']);

        $third = $sync->pull($this->context, 'default', $second['cursor'], 2);
        $this->assertSame([5], array_column($third['entries'], 'change_sequence'));
        $this->assertFalse($third['has_more']);
    }

    public function test_pull_is_scoped_to_the_trusted_branch(): void
    {
        $this->changeEntry(1, $this->branch->id);
        $this->changeEntry(2, $this->otherBranch->id);
        $this->changeEntry(3, null); // tenant-wide reference delta

        $sync = app(OfflineSyncService::class);
        $feed = $sync->pull($this->context, 'default', 0, 50);
        $this->assertSame([1, 3], array_column($feed['entries'], 'change_sequence'));
    }

    public function test_pull_output_carries_no_payload_bodies(): void
    {
        $this->changeEntry(1, $this->branch->id, ['secret' => 'do-not-leak']);
        $feed = app(OfflineSyncService::class)->pull($this->context, 'default', 0, 10);
        $this->assertArrayNotHasKey('manifest', $feed['entries'][0]);
        $this->assertStringNotContainsString('do-not-leak', json_encode($feed));
    }

    public function test_cursor_advance_regression_guard_and_resync(): void
    {
        $sync = app(OfflineSyncService::class);
        $sync->acknowledgePull($this->context, 'default', 5);
        $this->assertDatabaseHas('sync_pull_cursors', ['device_id' => $this->device->id,
            'stream' => 'default', 'last_sequence' => 5, 'state' => 'active']);

        $advanced = $sync->acknowledgePull($this->context, 'default', 8);
        $this->assertSame(8, $advanced->last_sequence);

        // A silent rewind is refused.
        try {
            $sync->acknowledgePull($this->context, 'default', 3);
            $this->fail('Expected a cursor regression rejection.');
        } catch (SalesException $exception) {
            $this->assertSame(SalesException::STALE_VERSION, $exception->errorCode);
        }

        // An explicit resync may rewind and marks the stream resyncing.
        $resynced = $sync->acknowledgePull($this->context, 'default', 3, true);
        $this->assertSame(3, $resynced->last_sequence);
        $this->assertSame('resyncing', $resynced->state);
    }

    public function test_pull_includes_safe_authoritative_order_snapshot(): void
    {
        $order = app(OrderService::class)->create($this->context, [
            'type' => 'takeaway', 'currency' => 'IQD', 'client_operation_id' => (string) Str::ulid(),
        ]);
        $feed = app(OfflineSyncService::class)->pull($this->context, 'default', 0, 10);

        $this->assertSame($order->id, $feed['entries'][0]['snapshot']['id']);
        $this->assertSame('takeaway', $feed['entries'][0]['snapshot']['type']);
        $this->assertArrayNotHasKey('actor_id', $feed['entries'][0]['snapshot']);
        $this->assertArrayHasKey('server_time', $feed);
    }

    public function test_expired_cursor_requires_resynchronization(): void
    {
        $this->changeEntry(10, $this->branch->id);

        try {
            app(OfflineSyncService::class)->pull($this->context, 'default', 2, 10);
            $this->fail('Expected an expired cursor rejection.');
        } catch (SalesException $exception) {
            $this->assertSame(SalesException::RESYNC_REQUIRED, $exception->errorCode);
            $this->assertSame(9, $exception->context['minimum_cursor']);
        }
    }

    private function changeEntry(int $sequence, ?string $branchId, array $manifest = []): void
    {
        SyncChangeLogEntry::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $branchId, 'change_sequence' => $sequence,
            'entity_type' => 'order', 'entity_id' => (string) Str::ulid(), 'entity_revision' => 1,
            'operation' => 'upsert', 'manifest' => $manifest, 'occurred_at' => now(),
        ]);
    }

    private function tombstone(int $sequence, string $branchId): void
    {
        SyncTombstone::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $branchId, 'entity_type' => 'order',
            'entity_id' => (string) Str::ulid(), 'deletion_revision' => 1, 'change_sequence' => $sequence,
            'deleted_at' => now(), 'retention_until' => now()->addDays(90),
        ]);
    }
}
