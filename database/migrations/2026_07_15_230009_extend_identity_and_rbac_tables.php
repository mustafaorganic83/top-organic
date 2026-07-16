<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_groups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable();
            $table->string('employee_code')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('preferred_locale', 16)->default('ar');
            $table->string('account_status', 24)->default('active');
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('lock_expires_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedBigInteger('password_version')->default(1);
            $table->unsignedBigInteger('security_version')->default(1);
            $table->unsignedBigInteger('authorization_version')->default(1);
            $table->boolean('two_factor_enabled')->default(false);
        });

        Schema::table('roles', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable();
            $table->foreignUlid('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->string('status', 24)->default('active');
        });

        Schema::table('permissions', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable();
            $table->foreignUlid('permission_group_id')->nullable()
                ->constrained('permission_groups')->nullOnDelete();
            $table->text('description')->nullable();
            $table->string('risk_level', 16)->default('standard');
        });

        foreach (['users', 'roles', 'permissions'] as $table) {
            DB::table($table)->whereNull('public_id')->orderBy('id')->each(function ($row) use ($table): void {
                DB::table($table)->where('id', $row->id)->update([
                    'public_id' => strtolower((string) Str::ulid()),
                ]);
            });

            Schema::table($table, function (Blueprint $table): void {
                $table->ulid('public_id')->nullable(false)->change();
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('public_id');
            $table->unique(['tenant_id', 'employee_code']);
            $table->unique(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'account_status']);
            $table->index(['tenant_id', 'locked_at']);
        });

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique('roles_name_unique');
            $table->unique('public_id');
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('permissions', function (Blueprint $table): void {
            $table->unique('public_id');
            $table->index(['permission_group_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('permission_group_id');
            $table->dropColumn(['public_id', 'description', 'risk_level']);
        });

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'name']);
            $table->unique('name');
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn(['public_id', 'description', 'is_system', 'status']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'public_id', 'employee_code', 'phone', 'preferred_locale',
                'account_status', 'failed_login_attempts', 'locked_at',
                'lock_expires_at', 'password_changed_at', 'last_login_at',
                'password_version', 'security_version', 'authorization_version',
                'two_factor_enabled',
            ]);
        });

        Schema::dropIfExists('permission_groups');
    }
};
