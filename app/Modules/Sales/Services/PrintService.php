<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\BranchCatalogItem;
use App\Models\DocumentPrintEvent;
use App\Models\Invoice;
use App\Models\KdsTicket;
use App\Models\Order;
use App\Models\PrintAttempt;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\PrintRoute;
use App\Models\ProductVariant;
use App\Modules\Sales\Data\SalesContext;
use App\Modules\Sales\Exceptions\SalesException;
use App\Modules\Sales\Http\Resources\SalesResource;
use Illuminate\Support\Facades\DB;

final class PrintService
{
    public function enqueue(SalesContext $context, string $type, string $documentId, string $key, ?string $printerId, ?string $operation): PrintJob
    {
        $existing = PrintJob::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            if ($existing->payload_type !== $type || $existing->document_id !== $documentId) {
                throw SalesException::conflict(SalesException::IDEMPOTENCY_CONFLICT, 'The print idempotency key was reused for another payload.');
            }

            return $existing;
        }

        return DB::transaction(function () use ($context, $type, $documentId, $key, $printerId, $operation): PrintJob {
            [$documentType, $snapshot] = $this->snapshot($context, $type, $documentId);
            [$printer, $route] = $this->resolvePrinter($context, $type, $documentType, $documentId, $printerId);
            $payload = ['protocol' => 'top-organic.edge-print', 'version' => (int) config('sales.printing.protocol_version', 1),
                'payload_type' => $type, 'created_at' => now()->toISOString(), 'document' => $snapshot];
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return PrintJob::withoutGlobalScopes()->create(['tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'printer_id' => $printer->id, 'print_route_id' => $route?->id, 'payload_type' => $type,
                'document_type' => $documentType, 'document_id' => $documentId, 'payload' => $payload,
                'payload_hash' => hash('sha256', $json), 'state' => 'pending', 'priority' => $route?->priority ?? 100,
                'attempt_count' => 0, 'idempotency_key' => $key, 'client_operation_id' => $operation, 'available_at' => now()]);
        }, 3);
    }

    public function claim(SalesContext $context): ?PrintJob
    {
        return DB::transaction(function () use ($context): ?PrintJob {
            $printerIds = Printer::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('device_id')->orWhere('device_id', $context->deviceId))->pluck('id');
            $job = PrintJob::withoutGlobalScopes()->where('tenant_id', $context->tenantId)->where('branch_id', $context->branchId)
                ->where('state', 'pending')->whereIn('printer_id', $printerIds)
                ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->orderBy('priority')->oldest()->lockForUpdate()->first();
            if ($job === null) {
                return null;
            }
            if ($job->attempt_count >= (int) config('sales.printing.max_attempts', 5)) {
                $job->fill(['state' => 'failed', 'failed_at' => now(), 'lock_version' => $job->lock_version + 1])->save();

                return null;
            }
            $job->fill(['state' => 'processing', 'attempt_count' => $job->attempt_count + 1,
                'lock_version' => $job->lock_version + 1])->save();

            return $job->refresh();
        }, 3);
    }

    public function complete(SalesContext $context, string $id, int $version): PrintJob
    {
        return $this->finish($context, $id, $version, true);
    }

    public function fail(SalesContext $context, string $id, int $version, string $code, string $message): PrintJob
    {
        return $this->finish($context, $id, $version, false, $code, $message);
    }

    public function retry(SalesContext $context, string $id, int $version): PrintJob
    {
        return DB::transaction(function () use ($context, $id, $version): PrintJob {
            $job = $this->job($context, $id);
            if ($job->lock_version !== $version) {
                throw SalesException::conflict(SalesException::STALE_VERSION, 'The print job was changed.');
            }
            if ($job->state !== 'failed' || $job->attempt_count >= (int) config('sales.printing.max_attempts', 5)) {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'Only a retryable failed print job can be queued again.');
            }
            $job->fill(['state' => 'pending', 'failed_at' => null,
                'available_at' => now()->addSeconds((int) config('sales.printing.retry_seconds', 30)),
                'lock_version' => $job->lock_version + 1])->save();

            return $job->refresh();
        }, 3);
    }

    private function finish(SalesContext $context, string $id, int $version, bool $success, ?string $code = null, ?string $message = null): PrintJob
    {
        return DB::transaction(function () use ($context, $id, $version, $success, $code, $message): PrintJob {
            $job = $this->job($context, $id);
            if ($job->lock_version !== $version) {
                throw SalesException::conflict(SalesException::STALE_VERSION, 'The print job was changed.');
            }
            if ($job->state !== 'processing') {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'Only a claimed print job can be completed.');
            }
            PrintAttempt::withoutGlobalScopes()->create(['tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'print_job_id' => $job->id, 'printer_id' => $job->printer_id, 'attempt_number' => $job->attempt_count,
                'result' => $success ? 'printed' : 'failed', 'error_code' => $code, 'error_message' => $message,
                'started_at' => now(), 'finished_at' => now()]);
            $job->fill(['state' => $success ? 'printed' : 'failed', 'printed_at' => $success ? now() : null,
                'failed_at' => $success ? null : now(), 'lock_version' => $job->lock_version + 1])->save();
            if ($success && in_array($job->document_type, ['invoice', 'receipt'], true)) {
                $copy = DocumentPrintEvent::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                    ->where('branch_id', $context->branchId)->where('document_type', $job->document_type)
                    ->where('document_id', $job->document_id)->count() + 1;
                DocumentPrintEvent::withoutGlobalScopes()->create(['tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                    'invoice_id' => $job->document_id, 'document_type' => $job->document_type, 'document_id' => $job->document_id,
                    'actor_id' => $context->userId, 'device_id' => $context->deviceId, 'format' => 'json',
                    'copy_number' => $copy, 'occurred_at' => now()]);
            }

            return $job->refresh();
        }, 3);
    }

    private function job(SalesContext $context, string $id): PrintJob
    {
        return PrintJob::withoutGlobalScopes()->where('tenant_id', $context->tenantId)->where('branch_id', $context->branchId)
            ->whereKey($id)->lockForUpdate()->first() ?? throw new SalesException(SalesException::NOT_FOUND, 404, 'The print job was not found.');
    }

    private function resolvePrinter(SalesContext $context, string $type, string $documentType, string $documentId, ?string $id): array
    {
        $route = null;
        if ($id === null) {
            $route = PrintRoute::withoutGlobalScopes()->where('tenant_id', $context->tenantId)->where('branch_id', $context->branchId)
                ->where('payload_type', $type)->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', $documentType))
                ->where(fn ($q) => $q->whereNull('source_id')->orWhere('source_id', $documentId))
                ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))->orderBy('priority')->first();
            $id = $route?->printer_id;
        }
        $printer = $id === null ? null : Printer::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($id)->where('status', 'active')->first();
        if ($printer === null) {
            throw SalesException::conflict(SalesException::INVALID_STATE, 'No active printer route is available for this payload.');
        }

        return [$printer, $route];
    }

    private function snapshot(SalesContext $context, string $type, string $id): array
    {
        if ($type === 'kitchen_ticket') {
            $ticket = KdsTicket::withoutGlobalScopes()->where('tenant_id', $context->tenantId)->where('branch_id', $context->branchId)->find($id)
                ?? throw new SalesException(SalesException::NOT_FOUND, 404, 'The kitchen ticket was not found.');

            return ['kds_ticket', SalesResource::ticket($ticket)];
        }
        if (in_array($type, ['invoice', 'receipt'], true)) {
            $invoice = Invoice::withoutGlobalScopes()->where('tenant_id', $context->tenantId)->where('branch_id', $context->branchId)->find($id)
                ?? throw new SalesException(SalesException::NOT_FOUND, 404, 'The billing document was not found.');

            return [$type, SalesResource::invoice($invoice)];
        }
        if ($type === 'barcode_label') {
            $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $context->tenantId)->with('product')->find($id);
            $listed = BranchCatalogItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)->where('branch_id', $context->branchId)
                ->where('product_variant_id', $id)->where('status', 'active')->exists();
            if ($variant === null || ! $listed) {
                throw new SalesException(SalesException::NOT_FOUND, 404, 'The catalog label item was not found.');
            }

            return ['product_variant', ['id' => $variant->id, 'sku' => $variant->product->sku, 'name' => $variant->product->name,
                'variant_name' => $variant->name, 'barcode' => $variant->barcode]];
        }
        $invoice = Invoice::withoutGlobalScopes()->where('tenant_id', $context->tenantId)->where('branch_id', $context->branchId)->find($id);
        $order = $invoice === null ? Order::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->find($id) : null;
        if ($invoice === null && $order === null) {
            throw new SalesException(SalesException::NOT_FOUND, 404, 'The verification document was not found.');
        }
        $claims = $invoice !== null ? ['type' => 'invoice', 'id' => $invoice->id, 'number' => $invoice->number,
            'total_amount' => $invoice->total_amount, 'currency' => $invoice->currency, 'issued_at' => $invoice->issued_at?->toISOString()]
            : ['type' => 'order', 'id' => $order->id, 'number' => $order->number, 'total_amount' => $order->total_amount,
                'currency' => $order->currency, 'issued_at' => $order->settled_at?->toISOString()];
        $signature = hash_hmac('sha256', json_encode($claims, JSON_THROW_ON_ERROR), (string) config('app.key'));

        return ['verification', ['claims' => $claims, 'signature' => $signature, 'algorithm' => 'HMAC-SHA256']];
    }
}
