<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->foreignUlid('warehouse_id')->nullable()->after('branch_id');
            $table->index(['tenant_id','branch_id','warehouse_id','stockable_type','stockable_id','occurred_at'], 'ix_inv_mov_wh_stockable');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropIndex('ix_inv_mov_wh_stockable');
            $table->dropColumn('warehouse_id');
        });
    }
};
