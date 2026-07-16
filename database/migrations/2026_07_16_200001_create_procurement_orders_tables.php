<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement module – part 2: Purchase Orders, Goods Receipts, Inspections,
 * Vendor Contracts, Payment Schedules, and the Procurement Audit Log.
 *
 * PO status lifecycle: draft → approved → sent → partially_received →
 *   received → closed → cancelled.
 * GoodsReceipt: draft → posted.
 * Inspection: pending → passed / failed.
 * VendorContract: active → expired / terminated.
 * PaymentSchedule: pending → paid / overdue.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Purchase Orders ─────────────────────────────────────────────────
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('quotation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 64);
            // draft, approved, sent, partially_received, received, closed, cancelled
            $table->string('status', 32)->default('draft');
            $table->string('currency', 3)->default('IQD');
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by')->nullable();
            $table->foreignUlid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'reference'], 'uq_purchase_orders_reference');
            $table->index(['tenant_id', 'branch_id', 'status'], 'ix_purchase_orders_status');
            $table->index(['tenant_id', 'supplier_id'], 'ix_purchase_orders_supplier');
        });

        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('stock_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 200);
            $table->decimal('quantity', 18, 6);
            $table->decimal('quantity_received', 18, 6)->default(0);
            $table->string('unit', 24);
            $table->unsignedBigInteger('unit_price_amount')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->unsignedInteger('line_number');
            $table->timestamps();
            $table->unique(['tenant_id', 'purchase_order_id', 'line_number'], 'uq_po_items_line');
        });

        // ── Goods Receipts (draft → posted) ────────────────────────────────
        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 64);
            $table->string('status', 24)->default('draft'); // draft, posted
            $table->text('notes')->nullable();
            $table->foreignUlid('received_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'reference'], 'uq_goods_receipts_reference');
            $table->index(['tenant_id', 'branch_id', 'status'], 'ix_goods_receipts_status');
        });

        Schema::create('goods_receipt_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('goods_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('purchase_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('stock_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 200);
            $table->decimal('quantity_ordered', 18, 6)->default(0);
            $table->decimal('quantity_received', 18, 6);
            $table->string('unit', 24);
            $table->unsignedBigInteger('unit_price_amount')->default(0);
            $table->unsignedInteger('line_number');
            $table->timestamps();
            $table->unique(['tenant_id', 'goods_receipt_id', 'line_number'], 'uq_gr_items_line');
        });

        // ── Inspections (pending → passed / failed) ─────────────────────────
        Schema::create('inspections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('goods_receipt_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('pending'); // pending, passed, failed
            $table->text('notes')->nullable();
            $table->json('findings')->nullable();
            $table->foreignUlid('inspected_by')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'goods_receipt_id'], 'ix_inspections_receipt');
        });

        // ── Vendor Contracts (active → expired / terminated) ────────────────
        Schema::create('vendor_contracts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained()->restrictOnDelete();
            $table->string('reference', 64);
            $table->string('status', 24)->default('active'); // active, expired, terminated
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedBigInteger('value_amount')->default(0);
            $table->string('currency', 3)->default('IQD');
            $table->text('terms')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'supplier_id', 'reference'], 'uq_vendor_contracts_reference');
            $table->index(['tenant_id', 'supplier_id', 'status'], 'ix_vendor_contracts_status');
        });

        // ── Payment Schedules (pending → paid / overdue) ────────────────────
        Schema::create('payment_schedules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('vendor_contract_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 64);
            $table->string('status', 24)->default('pending'); // pending, paid, overdue
            $table->date('due_date');
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('currency', 3)->default('IQD');
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'supplier_id', 'status'], 'ix_payment_schedules_status');
            $table->index(['tenant_id', 'due_date', 'status'], 'ix_payment_schedules_due');
        });

        // ── Procurement Audit Log ───────────────────────────────────────────
        Schema::create('procurement_audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->string('entity_type', 48);
            $table->ulid('entity_id');
            $table->string('action', 48);
            $table->json('changes')->nullable();
            $table->foreignUlid('actor_id')->nullable();
            $table->ulid('device_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['tenant_id', 'branch_id', 'entity_type', 'entity_id', 'occurred_at'], 'ix_proc_audit_entity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_audit_logs');
        Schema::dropIfExists('payment_schedules');
        Schema::dropIfExists('vendor_contracts');
        Schema::dropIfExists('inspections');
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
