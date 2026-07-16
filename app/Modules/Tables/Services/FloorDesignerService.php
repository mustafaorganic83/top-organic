<?php

declare(strict_types=1);

namespace App\Modules\Tables\Services;

use App\Models\Floor;
use App\Models\Room;
use App\Modules\Tables\Data\ReservationContext;
use App\Modules\Tables\Exceptions\ReservationException;
use App\Modules\Tables\Services\Concerns\WritesReservationAudit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Owns the floor-plan layout: floors, rooms (VIP/private), and the tables that
 * live on them. Every mutation runs under optimistic locking, is scoped to the
 * trusted branch, and records an audit entry. Layout JSON stores designer
 * coordinates so the tablet/reception canvas can render the map.
 */
final class FloorDesignerService
{
    use WritesReservationAudit;

    /** @return Collection<int, Floor> */
    public function floors(ReservationContext $context): Collection
    {
        return Floor::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->with(['rooms' => fn ($q) => $q->withoutGlobalScopes(),
                'tables' => fn ($q) => $q->withoutGlobalScopes()->orderBy('sort_order')])
            ->orderBy('code')->get();
    }

    /** @param array<string, mixed> $data */
    public function createFloor(ReservationContext $context, array $data): Floor
    {
        return DB::transaction(function () use ($context, $data): Floor {
            $this->assertUniqueCode(Floor::class, $context, (string) $data['code']);
            $floor = Floor::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'code' => $data['code'], 'name' => $data['name'],
                'layout' => $data['layout'] ?? null, 'layout_revision' => 1,
                'status' => $data['status'] ?? 'active', 'lock_version' => 0,
            ]);
            $this->audit($context, 'floor', $floor->id, 'floor.created', null, $floor->status);

            return $floor;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateFloor(ReservationContext $context, string $floorId, int $expectedVersion, array $data): Floor
    {
        return DB::transaction(function () use ($context, $floorId, $expectedVersion, $data): Floor {
            $floor = $this->floorForUpdate($context, $floorId);
            $this->assertVersion($floor->lock_version, $expectedVersion);
            $layoutChanged = array_key_exists('layout', $data);
            $floor->fill(array_filter([
                'name' => $data['name'] ?? null, 'status' => $data['status'] ?? null,
            ], fn ($v) => $v !== null));
            if ($layoutChanged) {
                $floor->layout = $data['layout'];
                $floor->layout_revision = $floor->layout_revision + 1;
            }
            $floor->lock_version = $floor->lock_version + 1;
            $floor->save();
            $this->audit($context, 'floor', $floor->id, 'floor.updated', null, $floor->status,
                $layoutChanged ? ['layout_revision' => $floor->layout_revision] : []);

            return $floor->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function createRoom(ReservationContext $context, array $data): Room
    {
        return DB::transaction(function () use ($context, $data): Room {
            $this->assertUniqueCode(Room::class, $context, (string) $data['code']);
            if (isset($data['floor_id'])) {
                $this->floorForUpdate($context, (string) $data['floor_id']);
            }
            $room = Room::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'floor_id' => $data['floor_id'] ?? null, 'code' => $data['code'], 'name' => $data['name'],
                'kind' => $data['kind'] ?? 'standard', 'capacity' => $data['capacity'] ?? 0,
                'minimum_spend_amount' => $data['minimum_spend_amount'] ?? null,
                'currency' => $data['currency'] ?? null,
                'requires_approval' => $data['requires_approval'] ?? false,
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'active', 'lock_version' => 0,
            ]);
            $this->audit($context, 'room', $room->id, 'room.created', null, $room->status,
                ['kind' => $room->kind]);

            return $room;
        }, 3);
    }

    private function assertUniqueCode(string $model, ReservationContext $context, string $code): void
    {
        $exists = $model::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('code', $code)->exists();
        if ($exists) {
            throw ReservationException::conflict(ReservationException::INVALID_STATE,
                'The code is already used within this branch.', ['code' => $code]);
        }
    }

    private function floorForUpdate(ReservationContext $context, string $id): Floor
    {
        return Floor::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($id)->lockForUpdate()->first()
            ?? throw ReservationException::notFound('The floor was not found in this branch.');
    }
}
