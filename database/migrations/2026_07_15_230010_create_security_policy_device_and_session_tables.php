<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_security_policies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('max_failed_login_attempts')->default(5);
            $table->unsignedSmallInteger('lockout_minutes')->default(15);
            $table->unsignedSmallInteger('password_min_length')->default(10);
            $table->unsignedSmallInteger('password_history_count')->default(5);
            $table->unsignedInteger('access_token_ttl_minutes')->default(15);
            $table->unsignedInteger('refresh_token_ttl_minutes')->default(43200);
            $table->unsignedSmallInteger('remember_device_days')->default(30);
            $table->unsignedSmallInteger('offline_login_hours')->default(24);
            $table->boolean('mfa_required')->default(false);
            $table->boolean('allow_remembered_devices')->default(true);
            $table->boolean('allow_offline_login')->default(false);
            $table->timestamps();
        });

        Schema::create('devices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('type', 32);
            $table->string('status', 24)->default('pending');
            $table->text('public_key')->nullable();
            $table->string('key_fingerprint', 128);
            $table->string('app_version', 64)->nullable();
            $table->string('os_version', 128)->nullable();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('authorization_requested_at')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'key_fingerprint']);
            $table->index(['tenant_id', 'branch_id', 'status']);
            $table->index(['tenant_id', 'status', 'last_seen_at']);
        });

        Schema::create('auth_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_key_hash', 128)->unique();
            $table->string('authentication_method', 32)->default('password');
            $table->boolean('mfa_completed')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->unsignedBigInteger('password_version');
            $table->unsignedBigInteger('security_version');
            $table->unsignedBigInteger('authorization_version');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'revoked_at']);
            $table->index(['tenant_id', 'device_id', 'revoked_at']);
            $table->index(['tenant_id', 'expires_at']);
        });

        Schema::create('refresh_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('auth_session_id')->constrained()->cascadeOnDelete();
            $table->ulid('family_id')->index();
            $table->foreignUlid('parent_token_id')->nullable()
                ->constrained('refresh_tokens')->nullOnDelete();
            $table->string('token_hash', 128)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUlid('replaced_by_token_id')->nullable()
                ->constrained('refresh_tokens')->nullOnDelete();
            $table->timestamps();

            $table->index(['auth_session_id', 'revoked_at', 'expires_at']);
            $table->index(['family_id', 'revoked_at']);
        });

        Schema::create('remembered_devices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('device_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 128)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'device_id']);
            $table->index(['tenant_id', 'user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remembered_devices');
        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('auth_sessions');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('tenant_security_policies');
    }
};
