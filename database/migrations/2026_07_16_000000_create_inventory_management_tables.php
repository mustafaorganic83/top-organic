<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory Management (architecture doc 01 FR-2). Builds on the Menu/Recipe
 * foundation (stock_items, semi_finished_products, stock_levels,
 * inventory_movements) rather than duplicating it. Adds multi-warehouse
 * storage, lot/batch tracking with expiry (FIFO/FEFO), stock transfers,
 * physical & cycle counts, purchase requests, and an inventory audit trail.
 *
 * Money is integer minor units; quantities decimal(18,6); costing uses moving
 * average per stock level and per-batch unit cost for FIFO/FEFO issue.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Identification & planning fields on the existing stock catalog.
        Schema::table('stock_items', function (Blueprint $table): void {
            $table->string('barcode', 128)->nullable()->after('name');
            $table->string('qr_code', 256)->nullable()->after('barcode');
            $table->boolean('is_perishable')->default(false)->after('kind');
            $table->boolean('is_batch_tracked')->default(false)->after('is_perishable');
            $table->string('costing_method', 16)->default('average')->after('unit_cost_amount'); // average, fifo, fefo
            $table->decimal('min_stock', 18, 6)->default(0)->after('default_waste_bps');
            $table->decimal('max_stock', 18, 6)->default(0)->after('min_stock');
            $table->decimal('reorder_point', 18, 6)->default(0)->after('max_stock');
            $table->decimal('reorder_quantity', 18, 6)->default(0)->after('reorder_point');
            $table->index(['tenant_id', 'barcode'], 'ix_stock_items_barcode');
        });

        // Stock levels become warehouse-scoped and carry a moving-average cost.
        Schema::table('stock_levels', function (Blueprint $table): void {
            $table->foreignUlid('warehouse_id')->nullable()->after('branch_id');
            $table->unsignedBigInteger('average_cost_amount')->default(0)->after('reorder_level'); // moving avg, minor units
            $table->decimal('reserved_quantity', 18, 6)->default(0)->after('quantity_on_hand');
        });

        // A storage location within a branch (main store, walk-in fridge, bar).
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('type', 24)->default('store'); // store, fridge, freezer, bar, kitchen
            $table->boolean('is_default')->default(false);
            $table->boolean('is_sellable_source')->default(true); // consumption may draw from it
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'code'], 'uq_warehouses_code');
            $table->index(['tenant_id', 'branch_id', 'status'], 'ix_warehouses_status');
        });

        // A received lot of a stockable in a warehouse. FIFO orders by
        // received_at; FEFO orders by expiry_date. quantity_remaining is drawn
        // down as the lot is issued/consumed; unit_cost_amount is the lot cost.
        Schema::create('stock_batches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('stockable_type', 32); // stock_item, semi_finished_product
            $table->ulid('stockable_id');
            $table->string('batch_number', 128)->nullable();
            $table->string('lot_number', 128)->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('received_date')->nullable();
            $table->decimal('quantity_received', 18, 6);
            $table->decimal('quantity_remaining', 18, 6);
            $table->string('unit', 24);
            $table->unsignedBigInteger('unit_cost_amount')->default(0); // minor units per unit
            $table->string('currency', 3)->nullable();
            $table->string('status', 24)->default('open'); // open, depleted, expired, quarantined
            $table->timestamp('received_at');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            // Draw order: FIFO (received_at), FEFO (expiry_date). Both indexed.
            $table->index(['tenant_id', 'branch_id', 'warehouse_id', 'stockable_type', 'stockable_id', 'status', 'received_at'], 'ix_stock_batches_fifo');
            $table->index(['tenant_id', 'branch_id', 'warehouse_id', 'stockable_type', 'stockable_id', 'status', 'expiry_date'], 'ix_stock_batches_fefo');
        });

        // Stock transfer header between two warehouses (draft -> in_transit ->
        // received / cancelled). Issue deducts the source on dispatch; receipt
        // adds to the destination, so in-transit stock is neither side's.
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->string('reference', 64);
            $table->foreignUlid('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUlid('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 24)->default('draft'); // draft, in_transit, received, cancelled
            $table->text('notes')->nullable();
            $table->foreignUlid('dispatched_by')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignUlid('received_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('client_operation_id', 128)->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'reference'], 'uq_stock_transfers_reference');
            $table->index(['tenant_id', 'branch_id', 'status'], 'ix_stock_transfers_status');
        });

        Schema::create('stock_transfer_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('stock_transfer_id')->constrained()->restrictOnDelete();
            $table->string('stockable_type', 32);
            $table->ulid('stockable_id');
            $table->decimal('quantity', 18, 6);
            $table->decimal('quantity_received', 18, 6)->default(0);
            $table->string('unit', 24);
            $table->unsignedBigInteger('unit_cost_amount')->default(0);
            $table->unsignedInteger('line_number');
            $table->timestamps();
            $table->unique(['tenant_id', 'stock_transfer_id', 'line_number'], 'uq_transfer_items_line');
        });

        // Physical (full) or cycle (partial/rolling) count session. Counting
        // freezes the expected snapshot; posting writes adjustment movements
        // for each variance and reconciles the on-hand level.
        Schema::create('stock_counts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('reference', 64);
            $table->string('type', 16)->default('physical'); // physical, cycle
            $table->string('status', 24)->default('open'); // open, counting, posted, cancelled
            $table->text('notes')->nullable();
            $table->foreignUlid('counted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignUlid('posted_by')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'reference'], 'uq_stock_counts_reference');
            $table->index(['tenant_id', 'branch_id', 'warehouse_id', 'status'], 'ix_stock_counts_status');
        });

        Schema::create('stock_count_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('stock_count_id')->constrained()->restrictOnDelete();
            $table->string('stockable_type', 32);
            $table->ulid('stockable_id');
            $table->decimal('expected_quantity', 18, 6)->default(0); // snapshot at open
            $table->decimal('counted_quantity', 18, 6)->nullable(); // entered by counter
            $table->decimal('variance_quantity', 18, 6)->default(0); // counted - expected
            $table->string('unit', 24);
            $table->timestamps();
            $table->unique(['tenant_id', 'stock_count_id', 'stockable_type', 'stockable_id'], 'uq_count_items_stockable');
        });

        // A purchase request raised when stock runs low (draft -> submitted ->
        // approved / rejected). Feeds procurement; receiving is out of scope
        // and lands stock via the batch-receipt endpoint.
        Schema::create('purchase_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference', 64);
            $table->string('status', 24)->default('draft'); // draft, submitted, approved, rejected
            $table->string('source', 24)->default('manual'); // manual, auto_reorder
            $table->text('notes')->nullable();
            $table->foreignUlid('requested_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUlid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'reference'], 'uq_purchase_requests_reference');
            $table->index(['tenant_id', 'branch_id', 'status'], 'ix_purchase_requests_status');
        });

        Schema::create('purchase_request_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('purchase_request_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('stock_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->string('unit', 24);
            $table->unsignedBigInteger('estimated_unit_cost_amount')->default(0);
            $table->unsignedInteger('line_number');
            $table->timestamps();
            $table->unique(['tenant_id', 'purchase_request_id', 'line_number'], 'uq_pr_items_line');
        });

        // Append-only inventory audit trail: every warehouse/batch/transfer/
        // count/purchase-request/adjustment action, who did it, and a JSON
        // before/after snapshot for tamper-evident review.
        Schema::create('inventory_audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->string('entity_type', 48); // warehouse, stock_batch, stock_transfer, stock_count, ...
            $table->ulid('entity_id');
            $table->string('action', 48); // created, updated, dispatched, received, posted, ...
            $table->json('changes')->nullable(); // {before, after}
            $table->foreignUlid('actor_id')->nullable();
            $table->ulid('device_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['tenant_id', 'branch_id', 'entity_type', 'entity_id', 'occurred_at'], 'ix_inventory_audit_entity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_audit_logs');
        Schema::dropIfExists('purchase_request_items');
        Schema::dropIfExists('purchase_requests');
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_batches');
        Schema::dropIfExists('warehouses');
        Schema::table('stock_levels', function (Blueprint $table): void {
            $table->dropColumn(['warehouse_id', 'average_cost_amount', 'reserved_quantity']);
        });
        Schema::table('stock_items', function (Blueprint $table): void {
            $table->dropIndex('ix_stock_items_barcode');
            $table->dropColumn([
                'barcode', 'qr_code', 'is_perishable', 'is_batch_tracked', 'costing_method',
                'min_stock', 'max_stock', 'reorder_point', 'reorder_quantity',
            ]);
        });
    }
};
