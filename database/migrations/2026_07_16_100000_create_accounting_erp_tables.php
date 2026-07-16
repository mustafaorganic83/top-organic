<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting ERP module: Chart of Accounts, General Ledger, AP, AR,
 * Cash/Bank, Budget, Cost Centers, Projects, Journal Entries, Auto-posting.
 * All money values: integer minor-units. Rates: basis-points. ULIDs.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Chart of Accounts
        Schema::create('accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 200);
            $table->string('type', 30); // asset, liability, equity, revenue, expense, cogs
            $table->string('subtype', 50)->nullable(); // bank, cash, receivable, payable, …
            $table->ulid('parent_id')->nullable();
            $table->boolean('is_leaf')->default(true);
            $table->boolean('is_system')->default(false);
            $table->boolean('allow_direct_posting')->default(true);
            $table->string('currency', 3)->default('IQD');
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'uq_accounts_code');
            $table->index(['tenant_id', 'type', 'status'], 'ix_accounts_type');
        });

        // Cost Centers
        Schema::create('cost_centers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('code', 20);
            $table->string('name', 200);
            $table->string('type', 30)->default('branch'); // branch, department, project
            $table->ulid('parent_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'uq_cost_centers_code');
        });

        // Projects
        Schema::create('accounting_projects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('code', 20);
            $table->string('name', 200);
            $table->string('status', 20)->default('active');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->bigInteger('budget_amount')->default(0);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'uq_acc_projects_code');
        });

        // Budgets (per account + period)
        Schema::create('budgets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignUlid('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignUlid('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->string('fiscal_year', 10);
            $table->unsignedTinyInteger('period_month'); // 1-12
            $table->bigInteger('budgeted_amount')->default(0);
            $table->bigInteger('actual_amount')->default(0);
            $table->string('status', 20)->default('draft'); // draft, approved
            $table->timestamps();
            $table->unique(['tenant_id', 'account_id', 'cost_center_id', 'fiscal_year', 'period_month'], 'uq_budgets_period');
        });

        // Journal Entries (header)
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('reference', 50);
            $table->date('entry_date');
            $table->string('fiscal_year', 10);
            $table->unsignedTinyInteger('period_month');
            $table->string('source', 50)->default('manual'); // manual, auto_sale, auto_payment, auto_purchase, …
            $table->string('source_type', 100)->nullable();
            $table->ulid('source_id')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft'); // draft, posted, reversed
            $table->ulid('reversed_by')->nullable();
            $table->ulid('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'reference'], 'uq_journal_entries_ref');
            $table->index(['tenant_id', 'entry_date', 'status'], 'ix_je_date_status');
            $table->index(['tenant_id', 'source_type', 'source_id'], 'ix_je_source');
        });

        // Journal Entry Lines
        Schema::create('journal_entry_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignUlid('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained('accounting_projects')->nullOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->bigInteger('debit_amount')->default(0);
            $table->bigInteger('credit_amount')->default(0);
            $table->string('currency', 3)->default('IQD');
            $table->text('description')->nullable();
            $table->index(['tenant_id', 'account_id'], 'ix_jel_account');
            $table->index(['tenant_id', 'journal_entry_id'], 'ix_jel_entry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('accounting_projects');
        Schema::dropIfExists('cost_centers');
        Schema::dropIfExists('accounts');
    }
};
