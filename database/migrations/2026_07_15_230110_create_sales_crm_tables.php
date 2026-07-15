<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('customer_number', 64)->nullable();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('phone_hash', 128)->nullable();
            $table->string('email')->nullable();
            $table->string('email_hash', 128)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('status', 24)->default('active');
            $table->foreignUlid('merged_into_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->timestamp('last_order_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'customer_number']);
            $table->unique(['tenant_id', 'phone_hash']);
            $table->unique(['tenant_id', 'email_hash']);
            $table->index(['tenant_id', 'status', 'last_order_at']);
        });

        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('customer_id')->constrained()->restrictOnDelete();
            $table->string('label', 64);
            $table->string('recipient_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('line_one');
            $table->string('line_two')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'customer_id', 'status', 'is_default'], 'ix_customer_addresses_default');
        });

        Schema::create('membership_tiers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->unsignedBigInteger('minimum_spend')->default(0);
            $table->unsignedInteger('discount_rate_bps')->default(0);
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'minimum_spend']);
        });

        Schema::create('customer_memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('customer_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('membership_tier_id')->constrained()->restrictOnDelete();
            $table->string('membership_number', 64);
            $table->string('status', 24)->default('active');
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'membership_number']);
            $table->index(['tenant_id', 'customer_id', 'status', 'expires_at'], 'ix_customer_memberships_active');
        });

        Schema::create('discount_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('type', 32);
            $table->unsignedInteger('rate_bps')->nullable();
            $table->unsignedBigInteger('fixed_amount')->nullable();
            $table->unsignedBigInteger('minimum_order_amount')->nullable();
            $table->unsignedBigInteger('maximum_discount_amount')->nullable();
            $table->string('currency', 3)->nullable();
            $table->json('conditions')->nullable();
            $table->string('status', 24)->default('draft');
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code', 'revision']);
            $table->index(['tenant_id', 'status', 'effective_from', 'effective_to'], 'ix_discount_rules_effective');
        });

        Schema::create('coupons', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('discount_rule_id')->constrained()->restrictOnDelete();
            $table->string('code_hash', 128);
            $table->string('code_last4', 4);
            $table->unsignedInteger('maximum_redemptions')->nullable();
            $table->unsignedInteger('maximum_per_customer')->nullable();
            $table->unsignedInteger('redemption_count')->default(0);
            $table->string('status', 24)->default('active');
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code_hash']);
            $table->index(['tenant_id', 'status', 'effective_from', 'effective_to'], 'ix_coupons_effective');
        });

        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('coupon_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->ulid('order_id');
            $table->string('client_operation_id', 128);
            $table->unsignedBigInteger('discount_amount');
            $table->string('currency', 3);
            $table->timestamp('redeemed_at');
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'client_operation_id'], 'uq_coupon_redemptions_operation');
            $table->index(['tenant_id', 'coupon_id', 'redeemed_at']);
            $table->index(['tenant_id', 'customer_id', 'redeemed_at']);
        });

        Schema::create('gift_cards', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('token_hash', 128);
            $table->string('token_last4', 4);
            $table->string('currency', 3);
            $table->bigInteger('balance_amount')->default(0);
            $table->string('status', 24)->default('active');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'token_hash']);
            $table->index(['tenant_id', 'status', 'expires_at']);
        });

        Schema::create('gift_card_transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('gift_card_id')->constrained()->restrictOnDelete();
            $table->ulid('order_id')->nullable();
            $table->foreignUlid('original_transaction_id')->nullable()->constrained('gift_card_transactions')->restrictOnDelete();
            $table->string('type', 32);
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('currency', 3);
            $table->string('client_operation_id', 128);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'client_operation_id']);
            $table->index(['tenant_id', 'gift_card_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_card_transactions');
        Schema::dropIfExists('gift_cards');
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('discount_rules');
        Schema::dropIfExists('customer_memberships');
        Schema::dropIfExists('membership_tiers');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customers');
    }
};
