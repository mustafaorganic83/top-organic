<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts Payable, Accounts Receivable, Cash/Bank ledgers, Auto-posting rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Suppliers (Accounts Payable counterparties)
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 200);
            $table->string('tax_number', 50)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 200)->nullable();
            $table->text('address')->nullable();
            $table->foreignUlid('ap_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('payment_terms', 50)->default('net30');
            $table->string('currency', 3)->default('IQD');
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'uq_suppliers_code');
        });

        // AP Invoices (Bills from suppliers)
        Schema::create('ap_invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignUlid('supplier_id')->constrained()->restrictOnDelete();
            $table->string('reference', 50);
            $table->string('supplier_reference', 100)->nullable();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->bigInteger('subtotal_amount')->default(0);
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('total_amount')->default(0);
            $table->bigInteger('paid_amount')->default(0);
            $table->bigInteger('balance_amount')->default(0);
            $table->string('currency', 3)->default('IQD');
            $table->string('status', 20)->default('draft'); // draft, approved, partial, paid, cancelled
            $table->ulid('journal_entry_id')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'reference'], 'uq_ap_invoices_ref');
            $table->index(['tenant_id', 'supplier_id', 'status'], 'ix_ap_supplier_status');
            $table->index(['tenant_id', 'due_date', 'status'], 'ix_ap_due_date');
        });

        // AP Payments
        Schema::create('ap_payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignUlid('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('ap_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 50);
            $table->date('payment_date');
            $table->bigInteger('amount')->default(0);
            $table->string('method', 30)->default('bank_transfer'); // cash, bank_transfer, cheque
            $table->string('currency', 3)->default('IQD');
            $table->ulid('bank_account_id')->nullable();
            $table->ulid('journal_entry_id')->nullable();
            $table->string('status', 20)->default('pending'); // pending, cleared, bounced, cancelled
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'reference'], 'uq_ap_payments_ref');
        });

        // AR Customers (links to existing customers table)
        // Using existing customers table for AR counterparties; we add AR invoices.
        Schema::create('ar_invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->ulid('order_invoice_id')->nullable(); // link to sales Invoice
            $table->string('reference', 50);
            $table->date('invoice_date');
            $table->date('due_date');
            $table->bigInteger('total_amount')->default(0);
            $table->bigInteger('paid_amount')->default(0);
            $table->bigInteger('balance_amount')->default(0);
            $table->string('currency', 3)->default('IQD');
            $table->string('status', 20)->default('open'); // open, partial, paid, written_off
            $table->ulid('journal_entry_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'reference'], 'uq_ar_invoices_ref');
            $table->index(['tenant_id', 'due_date', 'status'], 'ix_ar_due_date');
        });

        // Bank Accounts
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignUlid('account_id')->constrained('accounts')->restrictOnDelete(); // GL account
            $table->string('code', 20);
            $table->string('name', 200);
            $table->string('bank_name', 200)->nullable();
            $table->string('account_number', 100)->nullable();
            $table->string('iban', 100)->nullable();
            $table->string('type', 20)->default('checking'); // checking, savings, cash_box
            $table->string('currency', 3)->default('IQD');
            $table->bigInteger('opening_balance')->default(0);
            $table->bigInteger('current_balance')->default(0);
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'uq_bank_accounts_code');
        });

        // Bank Transactions (reconciliation ledger)
        Schema::create('bank_transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('bank_account_id')->constrained()->restrictOnDelete();
            $table->date('transaction_date');
            $table->string('description', 500)->nullable();
            $table->bigInteger('debit_amount')->default(0);
            $table->bigInteger('credit_amount')->default(0);
            $table->bigInteger('running_balance')->default(0);
            $table->string('reference', 100)->nullable();
            $table->string('status', 20)->default('unreconciled'); // unreconciled, reconciled, void
            $table->ulid('journal_entry_id')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'bank_account_id', 'transaction_date'], 'ix_bank_txn_date');
        });

        // Auto-posting Rules
        Schema::create('auto_posting_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 100); // sale_completed, payment_received, purchase_received, …
            $table->string('name', 200);
            $table->json('debit_mapping');  // [{account_code, description, amount_field}]
            $table->json('credit_mapping');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'event_type', 'is_active'], 'ix_auto_rules_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_posting_rules');
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('ar_invoices');
        Schema::dropIfExists('ap_payments');
        Schema::dropIfExists('ap_invoices');
        Schema::dropIfExists('suppliers');
    }
};
