<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\BranchPaymentMethod;
use App\Models\Order;
use App\Modules\Kitchen\Data\KitchenContext;
use App\Modules\Kitchen\Services\ChefAssignmentService;
use App\Modules\Sales\Data\SalesContext;
use App\Modules\Sales\Data\SyncOperation;
use App\Modules\Sales\Exceptions\SalesException;
use Illuminate\Database\Eloquent\Model;

/**
 * Routes an offline SyncOperation to the existing trusted Sales service that
 * owns its business logic. Only additive, offline-safe commands are allowed;
 * anything else (coupon/gift-card redemption, payment reversal, catalog and
 * admin mutations) is rejected here so sync never becomes a bypass. No order
 * logic is duplicated — this is a thin, allow-listed router.
 */
final class OfflineCommandDispatcher
{
    /** @var array<string, string> command => group used for the allow-list */
    private const SAFE_COMMANDS = [
        'order.create' => 'order', 'order.item.add' => 'order', 'order.item.update' => 'order',
        'order.item.remove' => 'order', 'order.customer.set' => 'order', 'order.delivery.set' => 'order',
        'order.place' => 'order', 'order.state' => 'order', 'order.charges.replace' => 'order',
        'order.tip.add' => 'order',
        'payment.capture' => 'settlement',
        'pos.cash.movement' => 'pos',
        'pos.table.open' => 'pos', 'pos.table.close' => 'pos',
        'kitchen.ticket.start' => 'kitchen', 'kitchen.ticket.ready' => 'kitchen',
        'kitchen.ticket.serve' => 'kitchen', 'kitchen.ticket.assign' => 'kitchen',
        'kitchen.ticket.priority' => 'kitchen',
    ];

    public function __construct(
        private readonly OrderService $orders,
        private readonly SettlementService $settlement,
        private readonly PosService $pos,
        private readonly ChefAssignmentService $kitchen,
    ) {}

    public function isSafe(string $command): bool
    {
        return isset(self::SAFE_COMMANDS[$command]);
    }

    /**
     * Applies the operation via the owning service and returns the resulting
     * aggregate model. Callers wrap this in the batch transaction.
     */
    public function apply(SalesContext $context, SyncOperation $operation): Model
    {
        if (! $this->isSafe($operation->command)) {
            throw new SalesException(
                SalesException::SCOPE_VIOLATION,
                422,
                'The command is not permitted through offline synchronization.',
                ['command' => $operation->command],
            );
        }
        $p = $operation->payload;
        $id = str_starts_with($operation->command, 'order.') || $operation->command === 'payment.capture'
            ? $this->resolveOrderId($context, $operation->entityId, $operation->command)
            : $operation->entityId;
        $op = $operation->clientOperationId;
        $version = (int) ($p['expected_version'] ?? 0);

        return match ($operation->command) {
            'order.create' => $this->orders->create($context, [...$p, 'client_operation_id' => $op,
                'idempotency_key' => $operation->entityId]),
            'order.item.add' => $this->orders->addItem($context, $id, $version, (string) ($p['variant_id'] ?? ''),
                (string) ($p['quantity'] ?? ''), (array) ($p['modifiers'] ?? []), (string) ($p['channel'] ?? 'pos'), $op),
            'order.item.update' => $this->orders->updateItem($context, $id, (string) ($p['item_id'] ?? ''), $version,
                array_intersect_key($p, array_flip(['quantity', 'modifiers', 'course_number', 'seat_number', 'notes'])), $op),
            'order.item.remove' => $this->orders->removeItem($context, $id, (string) ($p['item_id'] ?? ''), $version, $op),
            'order.customer.set' => $this->orders->setCustomer($context, $id, $version, $p['customer_id'] ?? null, $op),
            'order.delivery.set' => $this->orders->setDelivery($context, $id, $version, (array) ($p['delivery'] ?? []), $op),
            'order.place' => $this->orders->place($context, $id, $version, $op),
            'order.state' => $this->orders->transition($context, $id, $version, (string) ($p['state'] ?? ''), $op),
            'order.charges.replace' => $this->orders->replaceCharges($context, $id, $version, (array) ($p['charges'] ?? []), $op),
            'order.tip.add' => $this->orders->addTip($context, $id, $version, (int) ($p['amount'] ?? 0), $op),
            'payment.capture' => $this->capturePayment($context, $id, $version, $p, $op),
            'pos.cash.movement' => $this->pos->recordCashMovement($context, $id, (string) ($p['type'] ?? ''),
                (int) ($p['amount'] ?? 0), (string) ($p['currency'] ?? ''), $op, $p['reason'] ?? null),
            'pos.table.open' => $this->pos->openTableSession($context, (string) ($p['table_id'] ?? ''),
                (int) ($p['guest_count'] ?? 0)),
            'pos.table.close' => $this->pos->closeTableSession($context, $id, $version),
            'kitchen.ticket.start' => $this->kitchen->transition($this->kitchenContext($context),
                $operation->entityId, $version, 'start', $op, $p['reason'] ?? null),
            'kitchen.ticket.ready' => $this->kitchen->transition($this->kitchenContext($context),
                $operation->entityId, $version, 'ready', $op, $p['reason'] ?? null),
            'kitchen.ticket.serve' => $this->kitchen->transition($this->kitchenContext($context),
                $operation->entityId, $version, 'serve', $op, $p['reason'] ?? null),
            'kitchen.ticket.assign' => $this->kitchen->assign($this->kitchenContext($context),
                $operation->entityId, $version, isset($p['chef_id']) ? (int) $p['chef_id'] : null, $op),
            'kitchen.ticket.priority' => $this->kitchen->setPriority($this->kitchenContext($context),
                $operation->entityId, $version, (bool) ($p['is_priority'] ?? false),
                isset($p['priority']) ? (int) $p['priority'] : null, $op),
            default => throw new SalesException(SalesException::SCOPE_VIOLATION, 422,
                'The command is not permitted through offline synchronization.', ['command' => $operation->command]),
        };
    }

    private function kitchenContext(SalesContext $context): KitchenContext
    {
        return new KitchenContext($context->tenantId, $context->branchId, $context->userId, $context->deviceId);
    }

    /** @param array<string, mixed> $payload */
    private function capturePayment(SalesContext $context, string $orderId, int $version, array $payload, string $operation): Model
    {
        $methodId = (string) ($payload['payment_method_id'] ?? '');
        $branchMethod = BranchPaymentMethod::withoutGlobalScopes()->with('paymentMethod')
            ->where('tenant_id', $context->tenantId)->where('branch_id', $context->branchId)
            ->where('payment_method_id', $methodId)->where('is_enabled', true)->first();
        $method = $branchMethod?->paymentMethod;
        if ($method === null || $method->kind === 'gift_card'
            || ($method->kind !== 'cash' && ! $branchMethod->supports_offline)) {
            throw SalesException::conflict(SalesException::INVALID_STATE,
                'The payment method is not enabled for offline capture.');
        }

        return $this->settlement->capture($context, $orderId, $version, $methodId,
            (int) ($payload['amount'] ?? 0), $operation, $operation);
    }

    private function resolveOrderId(SalesContext $context, string $id, string $command): string
    {
        if ($command === 'order.create') {
            return $id;
        }
        $order = Order::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->where(fn ($query) => $query->whereKey($id)->orWhere('idempotency_key', $id))->first();

        return $order?->id ?? $id;
    }
}
