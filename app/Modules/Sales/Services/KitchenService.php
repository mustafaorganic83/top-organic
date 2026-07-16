<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\BranchCatalogItem;
use App\Models\KdsStation;
use App\Models\KdsTicket;
use App\Models\KdsTicketEvent;
use App\Models\KdsTicketItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Modules\Sales\Data\SalesContext;
use App\Modules\Sales\Exceptions\SalesException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class KitchenService
{
    public function __construct(private readonly SequenceNumberService $numbers) {}

    public function hasActiveStation(SalesContext $context): bool
    {
        return KdsStation::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('status', 'active')->exists();
    }

    /** @return Collection<int, KdsTicket> */
    public function dispatch(SalesContext $context, string $orderId, string $operation): Collection
    {
        return DB::transaction(function () use ($context, $orderId, $operation): Collection {
            $order = Order::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->whereKey($orderId)->lockForUpdate()->first();
            if ($order === null || ! in_array($order->state, ['placed', 'confirmed', 'preparing', 'ready'], true)) {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'Only a placed active order can be sent to the kitchen.');
            }
            $stations = KdsStation::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('status', 'active')->get()->keyBy('id');
            $default = $stations->firstWhere('code', config('sales.kds.default_station_code')) ?? $stations->first();
            if ($default === null) {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'No active kitchen station is configured for this branch.');
            }
            $items = OrderItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('order_id', $order->id)->where('state', 'active')->get();
            $routes = BranchCatalogItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->whereIn('product_variant_id', $items->pluck('product_variant_id'))
                ->pluck('kds_station_id', 'product_variant_id');
            $grouped = $items->groupBy(fn (OrderItem $item) => $stations->has($routes[$item->product_variant_id] ?? null)
                ? $routes[$item->product_variant_id] : $default->id);
            $tickets = collect();
            foreach ($grouped as $stationId => $stationItems) {
                $ticket = KdsTicket::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                    ->where('branch_id', $context->branchId)->where('order_id', $order->id)
                    ->where('kds_station_id', $stationId)->lockForUpdate()->first();
                if ($ticket === null) {
                    $ticket = KdsTicket::withoutGlobalScopes()->create([
                        'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'order_id' => $order->id,
                        'kds_station_id' => $stationId, 'number' => $this->numbers->nextNumber($context, 'kds', $order->business_date),
                        'state' => 'queued', 'priority' => 100, 'last_sequence' => 0,
                    ]);
                    $this->event($context, $ticket, 'TicketQueued', $operation.':'.$stationId);
                }
                foreach ($stationItems as $item) {
                    KdsTicketItem::withoutGlobalScopes()->firstOrCreate([
                        'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                        'kds_ticket_id' => $ticket->id, 'order_item_id' => $item->id,
                    ], ['quantity' => $item->quantity, 'preparation_snapshot' => [
                        'name' => $item->product_name, 'variant_name' => $item->variant_name,
                        'modifiers' => $item->modifiers()->withoutGlobalScopes()->pluck('option_name')->values()->all(),
                        'course_number' => $item->course_number, 'notes' => $item->notes,
                    ], 'state' => 'queued']);
                }
                $tickets->push($ticket->refresh()->load(['station', 'items', 'events']));
            }

            return $tickets;
        }, 3);
    }

    public function transition(SalesContext $context, string $id, int $version, string $action, string $operation, ?string $reason = null): KdsTicket
    {
        $map = ['start' => ['queued', 'in_progress'], 'ready' => ['in_progress', 'ready'],
            'bump' => ['ready', 'bumped'], 'recall' => ['bumped', 'ready']];
        [$from, $to] = $map[$action] ?? throw SalesException::invalid('Unknown kitchen ticket action.');

        return DB::transaction(function () use ($context, $id, $version, $action, $operation, $reason, $from, $to): KdsTicket {
            $replay = KdsTicketEvent::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('client_operation_id', $operation)->first();
            if ($replay !== null) {
                return KdsTicket::withoutGlobalScopes()->findOrFail($replay->kds_ticket_id)->load(['station', 'items', 'events']);
            }
            $ticket = KdsTicket::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->whereKey($id)->lockForUpdate()->first()
                ?? throw new SalesException(SalesException::NOT_FOUND, 404, 'The kitchen ticket was not found.');
            if ($ticket->lock_version !== $version) {
                throw SalesException::conflict(SalesException::STALE_VERSION, 'The kitchen ticket was changed by another operation.');
            }
            if ($ticket->state !== $from) {
                throw SalesException::conflict(SalesException::INVALID_STATE, "Kitchen ticket cannot {$action} from {$ticket->state}.");
            }
            $changes = ['state' => $to, 'lock_version' => $ticket->lock_version + 1];
            if ($action === 'start') {
                $changes['started_at'] = now();
            }
            if (in_array($action, ['ready', 'recall'], true)) {
                $changes['ready_at'] = now();
            }
            if ($action === 'bump') {
                $changes['cleared_at'] = now();
            }
            $ticket->fill($changes)->save();
            KdsTicketItem::withoutGlobalScopes()->where('kds_ticket_id', $ticket->id)->update(['state' => $to]);
            $this->event($context, $ticket, 'Ticket'.ucfirst($action), $operation, $reason);

            return $ticket->refresh()->load(['station', 'items', 'events']);
        }, 3);
    }

    private function event(SalesContext $context, KdsTicket $ticket, string $type, string $operation, ?string $reason = null): void
    {
        $sequence = $ticket->last_sequence + 1;
        KdsTicketEvent::withoutGlobalScopes()->create(['tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
            'kds_ticket_id' => $ticket->id, 'sequence' => $sequence, 'event_type' => $type,
            'actor_id' => $context->userId, 'device_id' => $context->deviceId, 'reason' => $reason,
            'client_operation_id' => $operation, 'occurred_at' => now()]);
        $ticket->last_sequence = $sequence;
        $ticket->save();
    }
}
