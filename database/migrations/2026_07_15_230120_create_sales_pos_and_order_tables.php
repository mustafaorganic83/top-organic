<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->unsignedBigInteger('layout_revision')->default(1);
            $table->json('layout')->nullable();
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'code']);
            $table->index(['tenant_id', 'branch_id', 'status']);
        });

        Schema::create('dining_tables', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('floor_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name')->nullable();
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'code']);
            $table->index(['tenant_id', 'branch_id', 'floor_id', 'status']);
        });

        Schema::create('table_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('dining_table_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('guest_count')->default(1);
            $table->string('state', 24)->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->index(['tenant_id', 'branch_id', 'dining_table_id', 'state'], 'ix_table_sessions_table_state');
        });

        Schema::create('pos_shifts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->date('business_date');
            $table->unsignedInteger('sequence');
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('state', 24)->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'business_date', 'sequence'], 'uq_pos_shifts_daily_sequence');
            $table->index(['tenant_id', 'branch_id', 'state', 'opened_at']);
        });

        Schema::create('cash_drawers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'code']);
            $table->index(['tenant_id', 'branch_id', 'status']);
        });

        Schema::create('cash_drawer_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('cash_drawer_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('pos_shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('currency', 3);
            $table->unsignedBigInteger('opening_amount')->default(0);
            $table->bigInteger('expected_amount')->nullable();
            $table->bigInteger('counted_amount')->nullable();
            $table->bigInteger('variance_amount')->nullable();
            $table->string('state', 24)->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->index(['tenant_id', 'branch_id', 'cash_drawer_id', 'state'], 'ix_drawer_sessions_drawer_state');
            $table->index(['tenant_id', 'branch_id', 'pos_shift_id']);
        });

        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('cash_drawer_session_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('original_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->string('type', 32);
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->text('reason')->nullable();
            $table->string('client_operation_id', 128);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'client_operation_id'], 'uq_cash_movements_operation');
            $table->index(['tenant_id', 'branch_id', 'cash_drawer_session_id', 'occurred_at'], 'ix_cash_movements_session_time');
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('pos_shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('table_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('number', 64);
            $table->string('type', 24);
            $table->string('source', 32)->default('pos');
            $table->string('source_reference', 128)->nullable();
            $table->string('state', 32)->default('draft');
            $table->string('currency', 3);
            $table->unsignedBigInteger('subtotal_amount')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('charge_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('tip_amount')->default(0);
            $table->bigInteger('rounding_amount')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->unsignedBigInteger('due_amount')->default(0);
            $table->json('customer_snapshot')->nullable();
            $table->json('policy_snapshot')->nullable();
            $table->date('business_date');
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('client_operation_id', 128);
            $table->string('idempotency_key', 128)->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'number']);
            $table->unique(['tenant_id', 'branch_id', 'client_operation_id'], 'uq_orders_client_operation');
            $table->unique(['tenant_id', 'branch_id', 'idempotency_key'], 'uq_orders_idempotency');
            $table->index(['tenant_id', 'branch_id', 'state', 'created_at']);
            $table->index(['tenant_id', 'branch_id', 'business_date']);
            $table->index(['tenant_id', 'branch_id', 'source', 'source_reference'], 'ix_orders_source_reference');
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('parent_item_id')->nullable()->constrained('order_items')->restrictOnDelete();
            $table->foreignUlid('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('sku', 96)->nullable();
            $table->string('barcode', 128)->nullable();
            $table->decimal('quantity', 18, 6);
            $table->unsignedBigInteger('unit_price_amount');
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('net_amount');
            $table->string('currency', 3);
            $table->string('tax_class_code', 64)->nullable();
            $table->string('state', 24)->default('active');
            $table->unsignedSmallInteger('course_number')->nullable();
            $table->unsignedSmallInteger('seat_number')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'order_id', 'line_number'], 'uq_order_items_line');
            $table->index(['tenant_id', 'branch_id', 'order_id', 'state']);
        });

        Schema::create('order_item_modifiers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('modifier_group_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('modifier_option_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('group_name')->nullable();
            $table->string('option_name');
            $table->decimal('quantity', 18, 6);
            $table->unsignedBigInteger('unit_surcharge_amount')->default(0);
            $table->unsignedBigInteger('total_surcharge_amount')->default(0);
            $table->string('currency', 3);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'order_item_id', 'line_number'], 'uq_order_item_modifiers_line');
        });

        Schema::create('order_discounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('discount_rule_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('coupon_redemption_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 64)->nullable();
            $table->string('name');
            $table->string('type', 32);
            $table->unsignedInteger('rate_bps')->nullable();
            $table->unsignedBigInteger('value_amount')->nullable();
            $table->unsignedBigInteger('applied_amount');
            $table->string('currency', 3);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->index(['tenant_id', 'branch_id', 'order_id']);
        });

        Schema::create('order_charges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('tax_class_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('type', 32)->default('service_charge');
            $table->unsignedBigInteger('basis_amount')->default(0);
            $table->unsignedInteger('rate_bps')->nullable();
            $table->unsignedBigInteger('fixed_amount')->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->timestamp('occurred_at');
            $table->index(['tenant_id', 'branch_id', 'order_id', 'type']);
        });

        Schema::create('order_tax_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('tax_class_code', 64);
            $table->unsignedBigInteger('policy_revision')->nullable();
            $table->unsignedBigInteger('taxable_amount');
            $table->unsignedInteger('rate_bps');
            $table->unsignedBigInteger('tax_amount');
            $table->boolean('is_inclusive')->default(false);
            $table->unsignedInteger('calculation_order')->default(0);
            $table->string('currency', 3);
            $table->index(['tenant_id', 'branch_id', 'order_id', 'tax_class_code'], 'ix_order_tax_lines_rule');
        });

        Schema::create('order_tips', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('source', 32)->default('customer');
            $table->string('client_operation_id', 128);
            $table->timestamp('occurred_at');
            $table->unique(['tenant_id', 'branch_id', 'client_operation_id'], 'uq_order_tips_operation');
            $table->index(['tenant_id', 'branch_id', 'order_id']);
        });

        Schema::create('order_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('event_type', 64);
            $table->unsignedInteger('event_version')->default(1);
            $table->json('payload')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('client_operation_id', 128);
            $table->unsignedBigInteger('logical_clock')->default(0);
            $table->timestamp('occurred_at');
            $table->unique(['tenant_id', 'branch_id', 'order_id', 'sequence'], 'uq_order_events_sequence');
            $table->unique(['tenant_id', 'branch_id', 'client_operation_id'], 'uq_order_events_operation');
            $table->index(['tenant_id', 'branch_id', 'event_type', 'occurred_at']);
        });

        Schema::create('order_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('source_order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUlid('target_order_id')->constrained('orders')->restrictOnDelete();
            $table->string('relation', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->unique(['tenant_id', 'branch_id', 'source_order_id', 'target_order_id', 'relation'], 'uq_order_links_lineage');
            $table->index(['tenant_id', 'branch_id', 'target_order_id']);
        });

        Schema::create('delivery_fulfillments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('customer_address_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('address_snapshot');
            $table->json('contact_snapshot')->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('provider_reference', 128)->nullable();
            $table->string('state', 32)->default('pending');
            $table->unsignedBigInteger('fee_amount')->default(0);
            $table->string('currency', 3);
            $table->timestamp('promised_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'order_id']);
            $table->index(['tenant_id', 'branch_id', 'state', 'promised_at'], 'ix_delivery_state_promised');
        });

        Schema::create('delivery_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('delivery_fulfillment_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('event_type', 64);
            $table->string('state', 32)->nullable();
            $table->string('source', 32)->default('pos');
            $table->json('location')->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('client_operation_id', 128);
            $table->timestamp('occurred_at');
            $table->unique(['tenant_id', 'branch_id', 'delivery_fulfillment_id', 'sequence'], 'uq_delivery_events_sequence');
            $table->unique(['tenant_id', 'branch_id', 'client_operation_id'], 'uq_delivery_events_operation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_events');
        Schema::dropIfExists('delivery_fulfillments');
        Schema::dropIfExists('order_links');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_tips');
        Schema::dropIfExists('order_tax_lines');
        Schema::dropIfExists('order_charges');
        Schema::dropIfExists('order_discounts');
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_drawer_sessions');
        Schema::dropIfExists('cash_drawers');
        Schema::dropIfExists('pos_shifts');
        Schema::dropIfExists('table_sessions');
        Schema::dropIfExists('dining_tables');
        Schema::dropIfExists('floors');
    }
};
