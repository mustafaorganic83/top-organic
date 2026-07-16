<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_payment_methods', function (Blueprint $table): void {
            $table->boolean('supports_offline')->default(false)->after('is_enabled');
            $table->index(['tenant_id', 'branch_id', 'supports_offline'], 'ix_branch_payment_offline');
        });
    }

    public function down(): void
    {
        Schema::table('branch_payment_methods', function (Blueprint $table): void {
            $table->dropIndex('ix_branch_payment_offline');
            $table->dropColumn('supports_offline');
        });
    }
};
