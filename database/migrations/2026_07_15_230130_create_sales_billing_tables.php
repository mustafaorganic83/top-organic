<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('kind', 32);
            $table->string('provider', 64)->nullable();
            $table->string('config_reference')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'is_enabled']);
        });

        Schema::create('branch_payment_methods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('payment_method_id')->constrained()->restrictOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedBigInteger('minimum_amount')->nullable();
            $table->unsignedBigInteger('maximum_amount')->nullable();
            $table->string('settlement_account_code', 64)->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'payment_method_id'], 'uq_branch_payment_method');
            $table->index(['tenant_id', 'branch_id', 'is_enabled']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('captured_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 32);
            $table->bigInteger('tender_amount');
            $table->string('tender_currency', 3);
            $table->bigInteger('base_amount');
            $table->string('base_currency', 3);
            $table->decimal('fx_rate', 18, 8)->nullable();
            $table->string('provider_reference', 128)->nullable();
            $table->string('idempotency_key', 128);
            $table->string('client_operation_id', 128);
            $table->json('provider_snapshot')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('occurred_at');
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'idempotency_key'], 'uq_payments_idempotency');
            $table->unique(['tenant_id', 'branch_id', 'client_operation_id'], 'uq_payments_client_operation');
            $table->unique(['tenant_id', 'branch_id', 'provider_reference'], 'uq_payments_provider_reference');
            $table->index(['tenant_id', 'branch_id', 'captured_at', 'payment_method_id'], 'ix_payments_capture_method');
        });

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('payment_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->string('client_operation_id', 128);
            $table->timestamp('occurred_at');
            $table->unique(['tenant_id', 'branch_id', 'payment_id', 'order_id'], 'uq_payment_allocations_order');
            $table->unique(['tenant_id', 'branch_id', 'client_operation_id'], 'uq_payment_allocations_operation');
        });

        Schema::create('payment_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('payment_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('event_type', 64);
            $table->string('provider_status', 64)->nullable();
            $table->string('provider_reference', 128)->nullable();
            $table->json('payload')->nullable();
            $table->string('client_operation_id', 128);
            $table->timestamp('occurred_at');
            $table->unique(['tenant_id', 'branch_id', 'payment_id', 'sequence'], 'uq_payment_events_sequence');
            $table->unique(['tenant_id', 'branch_id', 'client_operation_id'], 'uq_payment_events_operation');
        });

        Schema::create('payment_reversals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('original_payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignUlid('reversal_payment_id')->constrained('payments')->restrictOnDelete();
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->text('reason');
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('client_operation_id', 128);
            $table->timestamp('occurred_at');
            $table->unique(['tenant_id', 'branch_id', 'reversal_payment_id'], 'uq_payment_reversal_payment');
            $table->unique(['tenant_id', 'branch_id', 'client_operation_id'], 'uq_payment_reversals_operation');
            $table->index(['tenant_id', 'branch_id', 'original_payment_id']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 32)->default('invoice');
            $table->string('number', 64);
            $table->date('business_date');
            $table->json('customer_snapshot')->nullable();
            $table->string('currency', 3);
            $table->unsignedBigInteger('subtotal_amount');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('charge_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->bigInteger('rounding_amount')->default(0);
            $table->unsignedBigInteger('total_amount');
            $table->unsignedBigInteger('policy_revision')->nullable();
            $table->string('status', 24)->default('issued');
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->string('client_operation_id', 128);
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'number']);
            $table->unique(['tenant_id', 'branch_id', 'client_operation_id'], 'uq_invoices_operation');
            $table->index(['tenant_id', 'branch_id', 'business_date', 'status']);
        });

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('description');
            $table->string('sku', 96)->nullable();
            $table->decimal('quantity', 18, 6);
            $table->unsignedBigInteger('unit_price_amount');
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('net_amount');
            $table->string('currency', 3);
            $table->unique(['tenant_id', 'branch_id', 'invoice_id', 'line_number'], 'uq_invoice_lines_line');
        });

        Schema::create('invoice_tax_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('invoice_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('tax_rule_code', 64);
            $table->unsignedBigInteger('tax_rule_revision')->nullable();
            $table->unsignedBigInteger('taxable_amount');
            $table->unsignedInteger('rate_bps');
            $table->unsignedBigInteger('tax_amount');
            $table->boolean('is_inclusive')->default(false);
            $table->unsignedInteger('calculation_order')->default(0);
            $table->string('currency', 3);
            $table->index(['tenant_id', 'branch_id', 'invoice_id', 'tax_rule_code'], 'ix_invoice_tax_lines_rule');
        });

        Schema::create('invoice_payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('payment_allocation_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->timestamp('occurred_at');
            $table->unique(['tenant_id', 'branch_id', 'invoice_id', 'payment_allocation_id'], 'uq_invoice_payments_allocation');
        });

        Schema::create('document_print_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('document_type', 32);
            $table->ulid('document_id');
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('format', 32);
            $table->unsignedInteger('copy_number')->default(1);
            $table->timestamp('occurred_at');
            $table->index(['tenant_id', 'branch_id', 'document_type', 'document_id', 'occurred_at'], 'ix_document_print_events_document');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_print_events');
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoice_tax_lines');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payment_reversals');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('branch_payment_methods');
        Schema::dropIfExists('payment_methods');
    }
};
