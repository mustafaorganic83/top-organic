<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_sequences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('scope_branch', 32);
            $table->string('scope', 64);
            $table->date('business_date');
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'scope_branch', 'scope', 'business_date'], 'uq_sales_sequences_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_sequences');
    }
};
