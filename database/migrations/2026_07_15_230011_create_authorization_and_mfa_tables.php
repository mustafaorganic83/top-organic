<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_branch_roles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('active_key')->nullable()->unique();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('effective_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            $table->index(
                ['tenant_id', 'user_id', 'branch_id', 'revoked_at'],
                'ix_user_branch_roles_user_active',
            );
            $table->index(
                ['tenant_id', 'branch_id', 'role_id', 'revoked_at'],
                'ix_user_branch_roles_branch_active',
            );
        });

        Schema::create('mfa_methods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('label')->nullable();
            $table->text('secret_ciphertext')->nullable();
            $table->string('credential_hash', 128)->nullable();
            $table->text('public_key')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'disabled_at']);
            $table->unique(['user_id', 'type', 'label']);
        });

        Schema::create('mfa_challenges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('mfa_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('auth_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('challenge_hash', 128);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'expires_at']);
            $table->index(['auth_session_id', 'consumed_at']);
        });

        Schema::create('mfa_recovery_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 128);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'code_hash']);
            $table->index(['tenant_id', 'user_id', 'used_at']);
        });

        Schema::create('password_histories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('password_hash');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_histories');
        Schema::dropIfExists('mfa_recovery_codes');
        Schema::dropIfExists('mfa_challenges');
        Schema::dropIfExists('mfa_methods');
        Schema::dropIfExists('user_branch_roles');
    }
};
