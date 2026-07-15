<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->json('catalog_snapshot')->nullable()->after('barcode');
        });
        Schema::table('order_tax_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('calculation_revision')->default(1)->after('order_item_id');
            $table->index(['tenant_id', 'branch_id', 'order_id', 'calculation_revision'], 'ix_order_tax_calculation');
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('tip_amount')->default(0)->after('tax_amount');
        });
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->json('catalog_snapshot')->nullable()->after('sku');
        });
        Schema::table('invoice_payments', function (Blueprint $table): void {
            $table->json('payment_snapshot')->nullable()->after('payment_allocation_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payments', fn (Blueprint $table) => $table->dropColumn('payment_snapshot'));
        Schema::table('invoice_lines', fn (Blueprint $table) => $table->dropColumn('catalog_snapshot'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('tip_amount'));
        Schema::table('order_tax_lines', function (Blueprint $table): void {
            $table->dropIndex('ix_order_tax_calculation');
            $table->dropColumn('calculation_revision');
        });
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('catalog_snapshot'));
    }
};
