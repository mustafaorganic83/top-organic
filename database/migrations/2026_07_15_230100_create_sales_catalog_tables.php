<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_classes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->unsignedInteger('rate_bps')->default(0);
            $table->boolean('is_inclusive')->default(false);
            $table->string('status', 24)->default('active');
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'effective_from']);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'parent_id', 'status', 'sort_order']);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('tax_class_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('sku', 96);
            $table->string('name');
            $table->string('type', 32)->default('standard');
            $table->boolean('is_sellable')->default(true);
            $table->string('status', 24)->default('active');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'category_id', 'status', 'sort_order']);
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name')->nullable();
            $table->string('barcode', 128)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'product_id', 'code']);
            $table->unique(['tenant_id', 'barcode']);
            $table->index(['tenant_id', 'product_id', 'status', 'sort_order']);
        });

        Schema::create('modifier_groups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->unsignedSmallInteger('min_selections')->default(0);
            $table->unsignedSmallInteger('max_selections')->nullable();
            $table->boolean('is_required')->default(false);
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('modifier_options', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('modifier_group_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->unsignedBigInteger('surcharge_amount')->default(0);
            $table->string('currency', 3);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'modifier_group_id', 'code'], 'uq_modifier_options_group_code');
            $table->index(['tenant_id', 'modifier_group_id', 'status', 'sort_order'], 'ix_modifier_options_group_status');
        });

        Schema::create('product_modifier_groups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('modifier_group_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedSmallInteger('min_selections')->nullable();
            $table->unsignedSmallInteger('max_selections')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'product_id', 'product_variant_id', 'modifier_group_id'], 'uq_product_modifier_target_group');
            $table->index(['tenant_id', 'modifier_group_id']);
        });

        Schema::create('price_lists', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('currency', 3);
            $table->string('channel', 32)->default('all');
            $table->unsignedBigInteger('revision')->default(1);
            $table->string('status', 24)->default('draft');
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code', 'revision']);
            $table->index(['tenant_id', 'status', 'effective_from', 'effective_to'], 'ix_price_lists_effective');
        });

        Schema::create('price_list_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('price_list_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('tax_class_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->timestamps();
            $table->unique(['tenant_id', 'price_list_id', 'product_variant_id'], 'uq_price_list_items_variant');
            $table->index(['tenant_id', 'product_variant_id', 'amount'], 'ix_price_items_variant_amount');
        });

        Schema::create('branch_price_lists', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('price_list_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'price_list_id', 'effective_from'], 'uq_branch_price_list_effective');
            $table->index(['tenant_id', 'branch_id', 'effective_from', 'effective_to'], 'ix_branch_price_lists_effective');
        });

        Schema::create('branch_catalog_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('product_variant_id')->constrained()->restrictOnDelete();
            $table->ulid('kds_station_id')->nullable();
            $table->boolean('is_available')->default(true);
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('source_revision')->default(1);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'product_variant_id'], 'uq_branch_catalog_variant');
            $table->index(['tenant_id', 'branch_id', 'is_available', 'kds_station_id'], 'ix_branch_catalog_availability');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_catalog_items');
        Schema::dropIfExists('branch_price_lists');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('product_modifier_groups');
        Schema::dropIfExists('modifier_options');
        Schema::dropIfExists('modifier_groups');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('tax_classes');
    }
};
