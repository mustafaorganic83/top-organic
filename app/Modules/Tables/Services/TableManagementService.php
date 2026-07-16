<?php

declare(strict_types=1);

namespace App\Modules\Tables\Services;

use App\Models\DiningTable;
use App\Models\Floor;
use App\Models\Room;
use App\Modules\Tables\Data\ReservationContext;
use App\Modules\Tables\Exceptions\ReservationException;
use App\Modules\Tables\Services\Concerns\WritesReservationAudit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Owns the dining-table catalogue and its live front-of-house occupancy state.
 * Occupancy (available/reserved/occupied/held/blocked/cleaning) is distinct
 * from the active/soft-delete lifecycle so the reception map reflects reality
 * without deleting configuration.
 */
final class TableManagementService
{
    use WritesReservationAudit;

    private const OCCUPANCY = ['available', 'reserved', 'occupied', 'held', 'blocked', 'cleaning'];

    /** @return Collection<int, DiningTable> */
    public function tables(ReservationContext $context, ?string $occupancy = null, ?string $area = null): Collection
    {
        return DiningTable::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->when($occupancy !== null, fn ($q) => $q->where('occupancy_status', $occupancy))
            ->when($area !== null, fn ($q) => $q->where('area', $area))
            ->with(['floor' => fn ($q) => $q->withoutGlobalScopes(),
                'room' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('sort_order')->get();
    }

    /** @param array<string, mixed> $data */
    public function createTable(ReservationContext $context, array $data): DiningTable
    {
        return DB::transaction(function () use ($context, $data): DiningTable {
            $this->assertUniqueCode($context, (string) $data['code']);
            $this->assertFloor($context, (string) $data['floor_id']);
            if (isset($data['room_id'])) {
                $this->assertRoom($context, (string) $data['room_id']);
            }
            $table = DiningTable::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'floor_id' => $data['floor_id'], 'room_id' => $data['room_id'] ?? null,
                'code' => $data['code'], 'name' => $data['name'] ?? null,
                'area' => $data['area'] ?? 'indoor', 'shape' => $data['shape'] ?? 'square',
                'capacity' => $data['capacity'] ?? 1, 'is_reservable' => $data['is_reservable'] ?? true,
                'sort_order' => $data['sort_order'] ?? 0, 'status' => $data['status'] ?? 'active',
                'occupancy_status' => 'available', 'lock_version' => 0,
            ]);
            $this->audit($context, 'dining_table', $table->id, 'table.created', null, $table->occupancy_status,
                ['area' => $table->area]);

            return $table;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateTable(ReservationContext $context, string $tableId, int $expectedVersion, array $data): DiningTable
    {
        return DB::transaction(function () use ($context, $tableId, $expectedVersion, $data): DiningTable {
            $table = $this->tableForUpdate($context, $tableId);
            $this->assertVersion($table->lock_version, $expectedVersion);
            if (isset($data['room_id'])) {
                $this->assertRoom($context, (string) $data['room_id']);
            }
            $table->fill(array_filter([
                'name' => $data['name'] ?? null, 'area' => $data['area'] ?? null,
                'shape' => $data['shape'] ?? null, 'capacity' => $data['capacity'] ?? null,
                'sort_order' => $data['sort_order'] ?? null, 'status' => $data['status'] ?? null,
                'room_id' => $data['room_id'] ?? null,
            ], fn ($v) => $v !== null));
            if (array_key_exists('is_reservable', $data)) {
                $table->is_reservable = (bool) $data['is_reservable'];
            }
            $table->lock_version = $table->lock_version + 1;
            $table->save();
            $this->audit($context, 'dining_table', $table->id, 'table.updated', null, $table->occupancy_status);

            return $table->refresh();
        }, 3);
    }

    public function changeOccupancy(ReservationContext $context, string $tableId, string $status, int $expectedVersion): DiningTable
    {
        if (! in_array($status, self::OCCUPANCY, true)) {
            throw ReservationException::invalid('An unknown table occupancy status was requested.',
                ['allowed' => self::OCCUPANCY]);
        }

        return DB::transaction(function () use ($context, $tableId, $status, $expectedVersion): DiningTable {
            $table = $this->tableForUpdate($context, $tableId);
            $this->assertVersion($table->lock_version, $expectedVersion);
            $from = $table->occupancy_status;
            $table->occupancy_status = $status;
            $table->lock_version = $table->lock_version + 1;
            $table->save();
            $this->audit($context, 'dining_table', $table->id, 'table.occupancy', $from, $status);

            return $table->refresh();
        }, 3);
    }

    public function tableForUpdate(ReservationContext $context, string $id): DiningTable
    {
        return DiningTable::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($id)->lockForUpdate()->first()
            ?? throw ReservationException::notFound('The dining table was not found in this branch.');
    }

    private function assertUniqueCode(ReservationContext $context, string $code): void
    {
        $exists = DiningTable::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('code', $code)->exists();
        if ($exists) {
            throw ReservationException::conflict(ReservationException::INVALID_STATE,
                'The table code is already used within this branch.', ['code' => $code]);
        }
    }

    private function assertFloor(ReservationContext $context, string $floorId): void
    {
        $exists = Floor::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($floorId)->exists();
        if (! $exists) {
            throw ReservationException::notFound('The floor was not found in this branch.');
        }
    }

    private function assertRoom(ReservationContext $context, string $roomId): void
    {
        $exists = Room::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($roomId)->exists();
        if (! $exists) {
            throw ReservationException::notFound('The room was not found in this branch.');
        }
    }
}
