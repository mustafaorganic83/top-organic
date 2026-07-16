<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Services;

use App\Models\KdsTicket;
use App\Models\KdsTicketItem;
use App\Models\User;
use App\Modules\Kitchen\Data\KitchenContext;
use App\Modules\Kitchen\Exceptions\KitchenException;
use App\Modules\Kitchen\Services\Concerns\WritesKitchenEvents;
use App\Modules\Menu\Data\MenuContext;
use App\Modules\Menu\Services\InventoryService;
use Illuminate\Support\Facades\DB;

/**
 * Write side of the kitchen board: chef assignment, priority flagging, and the
 * ticket lifecycle transitions the kitchen drives — start (cooking), ready,
 * and serve. Serving captures prep time for analytics. All mutations are
 * optimistic-locked, replay-guarded by client_operation_id (offline safe), and
 * recorded on the shared kds_ticket_events trail.
 */
final class ChefAssignmentService
{
    use WritesKitchenEvents;

    /** Kitchen action => [from state, to state]. */
    private const TRANSITIONS = [
        'start' => ['queued', 'in_progress'],
        'ready' => ['in_progress', 'ready'],
        'serve' => ['ready', 'served'],
    ];

    public function assign(KitchenContext $context, string $id, int $version, ?int $chefId, string $operation): KdsTicket
    {
        return DB::transaction(function () use ($context, $id, $version, $chefId, $operation): KdsTicket {
            if (($replay = $this->replay($context, $operation)) !== null) {
                return $replay;
            }
            $ticket = $this->lock($context, $id);
            $this->assertVersion($ticket->lock_version, $version);
            if ($ticket->state === 'served' || $ticket->state === 'bumped') {
                throw KitchenException::conflict(KitchenException::INVALID_STATE,
                    'A completed ticket cannot be reassigned.');
            }
            if ($chefId !== null) {
                $this->assertChef($context, $chefId);
            }
            $ticket->chef_id = $chefId;
            $ticket->assigned_at = $chefId === null ? null : now();
            $ticket->lock_version = $ticket->lock_version + 1;
            $ticket->save();
            $this->event($context, $ticket, $chefId === null ? 'ChefUnassigned' : 'ChefAssigned',
                $operation, null, ['chef_id' => $chefId]);

            return $ticket->refresh()->load(['station', 'chef', 'items', 'events']);
        }, 3);
    }

    public function setPriority(KitchenContext $context, string $id, int $version, bool $isPriority, ?int $priority, string $operation): KdsTicket
    {
        return DB::transaction(function () use ($context, $id, $version, $isPriority, $priority, $operation): KdsTicket {
            if (($replay = $this->replay($context, $operation)) !== null) {
                return $replay;
            }
            $ticket = $this->lock($context, $id);
            $this->assertVersion($ticket->lock_version, $version);
            $ticket->is_priority = $isPriority;
            if ($priority !== null) {
                $ticket->priority = $priority;
            } elseif ($isPriority && $ticket->priority >= 100) {
                $ticket->priority = 10;
            }
            $ticket->lock_version = $ticket->lock_version + 1;
            $ticket->save();
            $this->event($context, $ticket, 'PriorityChanged', $operation, null,
                ['is_priority' => $isPriority, 'priority' => $ticket->priority]);

            return $ticket->refresh()->load(['station', 'chef', 'items', 'events']);
        }, 3);
    }

    public function transition(KitchenContext $context, string $id, int $version, string $action, string $operation, ?string $reason = null): KdsTicket
    {
        [$from, $to] = self::TRANSITIONS[$action]
            ?? throw KitchenException::invalid('Unknown kitchen ticket action.', ['action' => $action]);

        return DB::transaction(function () use ($context, $id, $version, $action, $operation, $reason, $from, $to): KdsTicket {
            if (($replay = $this->replay($context, $operation)) !== null) {
                return $replay;
            }
            $ticket = $this->lock($context, $id);
            $this->assertVersion($ticket->lock_version, $version);
            if ($ticket->state !== $from) {
                throw KitchenException::conflict(KitchenException::INVALID_STATE,
                    "The kitchen ticket cannot {$action} from {$ticket->state}.");
            }
            $ticket->state = $to;
            $ticket->lock_version = $ticket->lock_version + 1;
            $now = now();
            if ($action === 'start') {
                $ticket->started_at = $now;
                if ($ticket->sla_seconds === null) {
                    $ticket->sla_seconds = $ticket->station?->sla_seconds ?? $ticket->station?->default_prep_seconds;
                }
            }
            if ($action === 'ready') {
                $ticket->ready_at = $now;
                if ($ticket->started_at !== null) {
                    $ticket->prep_seconds = max(0, $now->diffInSeconds($ticket->started_at, true));
                }
            }
            if ($action === 'serve') {
                $ticket->served_at = $now;
            }
            $ticket->save();
            KdsTicketItem::withoutGlobalScopes()->where('kds_ticket_id', $ticket->id)->update([
                'state' => $to,
                ...$action === 'start' ? ['started_at' => $now] : [],
                ...$action === 'ready' ? ['ready_at' => $now] : [],
            ]);
            if ($action === 'serve') {
                $this->consumeInventory($context, $ticket);
            }
            $this->event($context, $ticket, 'Ticket'.ucfirst($action), $operation, $reason);

            return $ticket->refresh()->load(['station', 'chef', 'items', 'events']);
        }, 3);
    }

    /**
     * Automatic inventory consumption on serve. Explodes each served item's
     * recipe BOM and deducts stock via the Menu module's InventoryService.
     * Idempotent per ticket item (the ledger's client_operation_id guards a
     * replay) and resolved lazily so the Kitchen module carries no hard
     * dependency on Menu — a tenant without recipes simply consumes nothing.
     */
    private function consumeInventory(KitchenContext $context, KdsTicket $ticket): void
    {
        $inventory = app(InventoryService::class);
        $menuContext = new MenuContext(
            $context->tenantId, $context->branchId, $context->userId, $context->deviceId,
        );
        $items = KdsTicketItem::withoutGlobalScopes()->where('kds_ticket_id', $ticket->id)
            ->with('orderItem')->get();
        foreach ($items as $item) {
            $variantId = $item->orderItem?->product_variant_id;
            if ($variantId === null) {
                continue;
            }
            $inventory->consumeForVariant(
                $menuContext,
                $variantId,
                (float) $item->quantity,
                'kds-serve:'.$item->id,
                'order_item',
                $item->order_item_id,
            );
        }
    }

    private function lock(KitchenContext $context, string $id): KdsTicket
    {
        return KdsTicket::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->whereKey($id)->with('station')->lockForUpdate()->first()
            ?? throw KitchenException::notFound('The kitchen ticket was not found.');
    }

    private function assertChef(KitchenContext $context, int $chefId): void
    {
        $granted = User::query()->whereKey($chefId)->where('tenant_id', $context->tenantId)
            ->whereHas('branches', fn ($q) => $q->whereKey($context->branchId))->exists();
        if (! $granted) {
            throw KitchenException::invalid('The chef is not assigned to this branch.', ['chef_id' => $chefId]);
        }
    }
}
