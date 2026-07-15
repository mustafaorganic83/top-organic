<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_login_grants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('device_id')->constrained()->cascadeOnDelete();
            $table->string('grant_token_hash', 128)->unique();
            $table->json('permission_snapshot');
            $table->unsignedBigInteger('password_version');
            $table->unsignedBigInteger('security_version');
            $table->unsignedBigInteger('authorization_version');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            $table->index(
                ['tenant_id', 'branch_id', 'user_id', 'expires_at'],
                'ix_offline_grants_user_expiry',
            );
            $table->index(
                ['tenant_id', 'device_id', 'revoked_at'],
                'ix_offline_grants_device_active',
            );
        });

        Schema::create('offline_login_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('offline_login_grant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('device_id')->constrained()->cascadeOnDelete();
            $table->ulid('client_receipt_id');
            $table->string('result', 24);
            $table->string('ip_address', 45)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['offline_login_grant_id', 'client_receipt_id'],
                'uq_offline_receipts_grant_client',
            );
            $table->index(
                ['tenant_id', 'branch_id', 'occurred_at'],
                'ix_offline_receipts_scope_time',
            );
            $table->index(
                ['tenant_id', 'device_id', 'synced_at'],
                'ix_offline_receipts_device_sync',
            );
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('scope_key', 64);
            $table->string('category', 48);
            $table->string('action', 96);
            $table->string('target_type')->nullable();
            $table->string('target_id', 64)->nullable();
            $table->string('actor_type', 32)->default('user');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->ulid('device_id')->nullable();
            $table->ulid('auth_session_id')->nullable();
            $table->string('source', 32)->default('api');
            $table->string('result', 24)->default('success');
            $table->text('reason')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->ulid('request_id')->nullable();
            $table->ulid('correlation_id')->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->string('previous_hash', 128)->nullable();
            $table->string('entry_hash', 128)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('recorded_at')->useCurrent();

            $table->unique(['scope_key', 'sequence']);
            $table->index(['tenant_id', 'branch_id', 'recorded_at']);
            $table->index(['tenant_id', 'actor_id', 'recorded_at']);
            $table->index(
                ['tenant_id', 'target_type', 'target_id', 'recorded_at'],
                'ix_audit_logs_target_time',
            );
            $table->index(['tenant_id', 'category', 'recorded_at']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('offline_login_receipts');
        Schema::dropIfExists('offline_login_grants');
    }
};
