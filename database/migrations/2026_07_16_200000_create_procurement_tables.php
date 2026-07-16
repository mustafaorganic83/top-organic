<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement module (architecture doc 01 FR-3). Covers supplier management,
 * request for quotation (RFQ), quotation comparison, purchase orders, goods
 * receipt, inspection, vendor contracts, and payment schedules.
 *
 * Money: integer minor units. Quantities: decimal(18,6). lock_version for
 * optimistic concurrency. Lifecycle statuses are documented per table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Extra supplier fields needed by procurement (category, rating, lead-time).
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('category', 50)->nullable()->after('status');
            $table->unsignedTinyInteger('rating')->default(0)->after('category'); // 0-100
            $table->unsignedSmallInteger('lead_time_days')->nullable()->after('rating');
        });

        // ── Supplier Evaluations ────────────────────────────────────────────
        Schema::create('supplier_evaluations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('evaluator_id')->nullable(); // user
            $table->json('criteria')->nullable();             // {quality, delivery, price, ...}
            $table->decimal('score', 5, 2)->default(0);      // 0-100
            $table->text('notes')->nullable();
            $table->timestamp('evaluated_at');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'supplier_id'], 'ix_supplier_evals_supplier');
        });

        // ── Request for Quotation (draft → sent → closed) ──────────────────
        Schema::create('rfqs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->string('reference', 64);
            $table->string('status', 24)->default('draft'); // draft, sent, closed
            $table->text('notes')->nullable();
            $table->foreignUlid('requested_by')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'reference'], 'uq_rfqs_reference');
            $table->index(['tenant_id', 'branch_id', 'status'], 'ix_rfqs_status');
        });

        Schema::create('rfq_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('rfq_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('stock_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 200);
            $table->decimal('quantity', 18, 6);
            $table->string('unit', 24);
            $table->date('required_date')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('line_number');
            $table->timestamps();
            $table->unique(['tenant_id', 'rfq_id', 'line_number'], 'uq_rfq_items_line');
        });

        // ── Quotations (received → shortlisted → awarded / rejected) ───────
        Schema::create('quotations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('rfq_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('supplier_id')->constrained()->restrictOnDelete();
            $table->string('reference', 64);
            $table->string('status', 24)->default('received'); // received, shortlisted, awarded, rejected
            $table->string('currency', 3)->default('IQD');
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'supplier_id', 'reference'], 'uq_quotations_reference');
            $table->index(['tenant_id', 'rfq_id', 'status'], 'ix_quotations_rfq_status');
        });

        Schema::create('quotation_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('quotation_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('stock_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 200);
            $table->decimal('quantity', 18, 6);
            $table->string('unit', 24);
            $table->unsignedBigInteger('unit_price_amount')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->unsignedInteger('line_number');
            $table->timestamps();
            $table->unique(['tenant_id', 'quotation_id', 'line_number'], 'uq_quotation_items_line');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('rfq_items');
        Schema::dropIfExists('rfqs');
        Schema::dropIfExists('supplier_evaluations');
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn(['category', 'rating', 'lead_time_days']);
        });
    }
};
