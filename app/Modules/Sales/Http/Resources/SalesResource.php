<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\KdsTicket;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PrintJob;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class SalesResource
{
    public static function order(Order $order): array
    {
        $order->loadMissing(['items.modifiers', 'delivery']);

        return ['id' => $order->id, 'number' => $order->number, 'type' => $order->type, 'source' => $order->source,
            'state' => $order->state, 'currency' => $order->currency, 'subtotal_amount' => $order->subtotal_amount,
            'discount_amount' => $order->discount_amount, 'charge_amount' => $order->charge_amount,
            'tax_amount' => $order->tax_amount, 'tip_amount' => $order->tip_amount, 'rounding_amount' => $order->rounding_amount,
            'total_amount' => $order->total_amount, 'paid_amount' => $order->paid_amount, 'due_amount' => $order->due_amount,
            'customer_id' => $order->customer_id, 'table_session_id' => $order->table_session_id,
            'pos_shift_id' => $order->pos_shift_id, 'business_date' => (string) $order->business_date,
            'placed_at' => $order->placed_at?->toISOString(), 'settled_at' => $order->settled_at?->toISOString(),
            'lock_version' => $order->lock_version, 'items' => $order->items->map(fn ($item) => [
                'id' => $item->id, 'line_number' => $item->line_number, 'variant_id' => $item->product_variant_id,
                'name' => $item->product_name, 'variant_name' => $item->variant_name, 'sku' => $item->sku,
                'quantity' => $item->quantity, 'unit_price_amount' => $item->unit_price_amount,
                'gross_amount' => $item->gross_amount, 'discount_amount' => $item->discount_amount,
                'tax_amount' => $item->tax_amount, 'net_amount' => $item->net_amount, 'currency' => $item->currency,
                'state' => $item->state, 'course_number' => $item->course_number, 'seat_number' => $item->seat_number,
                'notes' => $item->notes, 'modifiers' => $item->modifiers->map(fn ($modifier) => [
                    'id' => $modifier->id, 'option_id' => $modifier->modifier_option_id, 'name' => $modifier->option_name,
                    'quantity' => $modifier->quantity, 'unit_surcharge_amount' => $modifier->unit_surcharge_amount,
                    'total_surcharge_amount' => $modifier->total_surcharge_amount, 'currency' => $modifier->currency,
                ])->values()->all(),
            ])->values()->all(), 'delivery' => $order->delivery === null ? null : [
                'state' => $order->delivery->state, 'address' => $order->delivery->address_snapshot,
                'contact' => $order->delivery->contact_snapshot, 'fee_amount' => $order->delivery->fee_amount,
                'currency' => $order->delivery->currency, 'promised_at' => $order->delivery->promised_at?->toISOString(),
            ]];
    }

    public static function customer(Customer $customer): array
    {
        $customer->loadMissing('memberships.tier');

        return ['id' => $customer->id, 'customer_number' => $customer->customer_number, 'name' => $customer->name,
            'phone' => $customer->phone, 'email' => $customer->email, 'locale' => $customer->locale,
            'status' => $customer->status, 'last_order_at' => $customer->last_order_at?->toISOString(),
            'lock_version' => $customer->lock_version, 'memberships' => $customer->memberships->map(fn ($membership) => [
                'id' => $membership->id, 'membership_number' => $membership->membership_number,
                'tier' => $membership->tier === null ? null : ['id' => $membership->tier->id, 'code' => $membership->tier->code,
                    'name' => $membership->tier->name, 'discount_rate_bps' => $membership->tier->discount_rate_bps],
                'status' => $membership->status, 'started_at' => $membership->started_at?->toISOString(),
                'expires_at' => $membership->expires_at?->toISOString(),
            ])->values()->all()];
    }

    public static function payment(Payment $payment): array
    {
        $payment->loadMissing('method');

        return ['id' => $payment->id, 'order_id' => $payment->order_id,
            'method' => $payment->method === null ? null : ['id' => $payment->method->id, 'code' => $payment->method->code,
                'name' => $payment->method->name, 'kind' => $payment->method->kind],
            'status' => $payment->status, 'tender_amount' => $payment->tender_amount,
            'tender_currency' => $payment->tender_currency, 'base_amount' => $payment->base_amount,
            'base_currency' => $payment->base_currency, 'captured_at' => $payment->captured_at?->toISOString()];
    }

    public static function invoice(Invoice $invoice): array
    {
        $invoice->loadMissing(['lines', 'taxLines', 'payments']);

        return ['id' => $invoice->id, 'order_id' => $invoice->order_id, 'document_type' => $invoice->document_type,
            'number' => $invoice->number, 'business_date' => (string) $invoice->business_date, 'currency' => $invoice->currency,
            'subtotal_amount' => $invoice->subtotal_amount, 'discount_amount' => $invoice->discount_amount,
            'charge_amount' => $invoice->charge_amount, 'tax_amount' => $invoice->tax_amount,
            'tip_amount' => $invoice->tip_amount, 'rounding_amount' => $invoice->rounding_amount,
            'total_amount' => $invoice->total_amount, 'status' => $invoice->status, 'issued_at' => $invoice->issued_at?->toISOString(),
            'lines' => $invoice->lines->map(fn ($line) => $line->only(['id', 'line_number', 'description', 'sku', 'quantity',
                'unit_price_amount', 'gross_amount', 'discount_amount', 'net_amount', 'currency']))->values()->all(),
            'payments' => $invoice->payments->map(fn ($payment) => ['id' => $payment->id, 'amount' => $payment->amount,
                'currency' => $payment->currency, 'method_code' => $payment->payment_snapshot['method_code'] ?? null,
                'method_name' => $payment->payment_snapshot['method_name'] ?? null,
                'kind' => $payment->payment_snapshot['kind'] ?? null])->values()->all()];
    }

    public static function ticket(KdsTicket $ticket): array
    {
        $ticket->loadMissing(['station', 'items', 'events']);

        return ['id' => $ticket->id, 'order_id' => $ticket->order_id, 'number' => $ticket->number,
            'station' => ['id' => $ticket->station->id, 'code' => $ticket->station->code, 'name' => $ticket->station->name],
            'state' => $ticket->state, 'priority' => $ticket->priority, 'lock_version' => $ticket->lock_version,
            'started_at' => $ticket->started_at?->toISOString(), 'ready_at' => $ticket->ready_at?->toISOString(),
            'items' => $ticket->items->map(fn ($item) => ['id' => $item->id, 'order_item_id' => $item->order_item_id,
                'quantity' => $item->quantity, 'preparation' => $item->preparation_snapshot, 'state' => $item->state])->values()->all(),
            'events' => $ticket->events->map(fn ($event) => ['id' => $event->id, 'sequence' => $event->sequence,
                'type' => $event->event_type, 'reason' => $event->reason, 'occurred_at' => $event->occurred_at?->toISOString()])->values()->all()];
    }

    public static function printJob(PrintJob $job): array
    {
        return ['id' => $job->id, 'printer_id' => $job->printer_id, 'payload_type' => $job->payload_type,
            'document_type' => $job->document_type, 'document_id' => $job->document_id, 'payload' => $job->payload,
            'state' => $job->state, 'priority' => $job->priority, 'attempt_count' => $job->attempt_count,
            'available_at' => $job->available_at?->toISOString(), 'printed_at' => $job->printed_at?->toISOString(),
            'lock_version' => $job->lock_version];
    }

    public static function paginated(LengthAwarePaginator $page, callable $map): array
    {
        return ['data' => collect($page->items())->map($map)->values()->all(), 'meta' => [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ]];
    }
}
