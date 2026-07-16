<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Threads the tenant scope onto users. A user belongs to one tenant and may
 * be granted one or many branches (see branch_user). `is_active` supports
 * the device/account lifecycle described in doc 06.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUlid('tenant_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn('is_active');
        });
    }
};
