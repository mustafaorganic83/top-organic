<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_idempotency_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('request_fingerprint', 128);
            $table->string('operation_type', 64);
            $table->string('result_code', 64);
            $table->json('result_body')->nullable();
            $table->string('entity_type', 64)->nullable();
            $table->ulid('entity_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['tenant_id', 'branch_id', 'device_id', 'idempotency_key'], 'uq_sales_idempotency_device_key');
            $table->index(['tenant_id', 'branch_id', 'expires_at']);
        });

        Schema::create('device_sequences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('device_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('next_sequence')->default(1);
            $table->unsignedBigInteger('logical_clock')->default(0);
            $table->unsignedBigInteger('last_acknowledged_sequence')->default(0);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'device_id']);
        });

        Schema::create('sync_batches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('device_id')->constrained()->restrictOnDelete();
            $table->ulid('client_batch_id');
            $table->string('direction', 16);
            $table->string('client_version', 64)->nullable();
            $table->unsignedInteger('schema_version');
            $table->unsignedInteger('operation_count')->default(0);
            $table->string('state', 24)->default('pending');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'device_id', 'client_batch_id'], 'uq_sync_batches_client_batch');
            $table->index(['tenant_id', 'branch_id', 'device_id', 'state', 'started_at'], 'ix_sync_batches_state');
        });

        Schema::create('sync_outbox_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('device_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('sync_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('request_fingerprint', 128);
            $table->string('entity_type', 64);
            $table->ulid('entity_id');
            $table->string('operation', 32);
            $table->unsignedInteger('payload_version')->default(1);
            $table->json('payload');
            $table->unsignedBigInteger('device_sequence');
            $table->unsignedBigInteger('logical_clock')->default(0);
            $table->string('state', 24)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('result_code', 64)->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'device_id', 'idempotency_key'], 'uq_sync_outbox_idempotency');
            $table->unique(['tenant_id', 'device_id', 'device_sequence'], 'uq_sync_outbox_device_sequence');
            $table->index(['tenant_id', 'branch_id', 'state', 'next_attempt_at', 'device_sequence'], 'ix_sync_outbox_pending');
        });

        Schema::create('sync_inbox_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('device_id')->constrained()->restrictOnDelete();
            $table->ulid('operation_id');
            $table->string('request_fingerprint', 128);
            $table->string('result', 32);
            $table->string('result_code', 64);
            $table->json('result_body')->nullable();
            $table->unsignedBigInteger('entity_revision')->nullable();
            $table->timestamp('applied_at');
            $table->unique(['tenant_id', 'device_id', 'operation_id'], 'uq_sync_inbox_operation');
            $table->index(['tenant_id', 'branch_id', 'applied_at']);
        });

        Schema::create('domain_outbox_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('aggregate_type', 64);
            $table->ulid('aggregate_id');
            $table->unsignedBigInteger('aggregate_sequence')->nullable();
            $table->string('event_type', 96);
            $table->unsignedInteger('event_version')->default(1);
            $table->json('payload');
            $table->string('correlation_id', 128)->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->string('state', 24)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'state', 'available_at']);
            $table->index(['tenant_id', 'aggregate_type', 'aggregate_id', 'aggregate_sequence'], 'ix_domain_outbox_aggregate');
        });

        Schema::create('sync_change_log_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('change_sequence');
            $table->string('entity_type', 64);
            $table->ulid('entity_id');
            $table->unsignedBigInteger('entity_revision');
            $table->string('operation', 32);
            $table->json('manifest')->nullable();
            $table->timestamp('occurred_at');
            $table->unique(['tenant_id', 'change_sequence']);
            $table->index(['tenant_id', 'entity_type', 'change_sequence']);
            $table->index(['tenant_id', 'branch_id', 'change_sequence']);
        });

        Schema::create('sync_pull_cursors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('device_id')->constrained()->restrictOnDelete();
            $table->string('stream', 64);
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->unsignedBigInteger('last_revision')->default(0);
            $table->string('state', 24)->default('active');
            $table->timestamp('last_pulled_at')->nullable();
            $table->timestamp('last_applied_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'device_id', 'stream'], 'uq_sync_pull_cursors_stream');
        });

        Schema::create('sync_tombstones', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('entity_type', 64);
            $table->ulid('entity_id');
            $table->unsignedBigInteger('deletion_revision');
            $table->unsignedBigInteger('change_sequence');
            $table->timestamp('deleted_at');
            $table->timestamp('retention_until')->nullable();
            $table->unique(['tenant_id', 'entity_type', 'entity_id', 'deletion_revision'], 'uq_sync_tombstones_revision');
            $table->index(['tenant_id', 'branch_id', 'change_sequence']);
        });

        Schema::create('sync_conflicts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('device_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('sync_outbox_operation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('entity_type', 64);
            $table->ulid('entity_id');
            $table->string('conflict_type', 64);
            $table->unsignedBigInteger('local_revision')->nullable();
            $table->unsignedBigInteger('remote_revision')->nullable();
            $table->json('local_snapshot')->nullable();
            $table->json('remote_snapshot')->nullable();
            $table->string('risk', 24)->default('normal');
            $table->string('state', 24)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('resolution', 32)->nullable();
            $table->text('resolution_reason')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'sync_outbox_operation_id'], 'uq_sync_conflicts_operation');
            $table->index(['tenant_id', 'branch_id', 'state', 'created_at']);
            $table->index(['tenant_id', 'entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('sync_tombstones');
        Schema::dropIfExists('sync_pull_cursors');
        Schema::dropIfExists('sync_change_log_entries');
        Schema::dropIfExists('domain_outbox_events');
        Schema::dropIfExists('sync_inbox_receipts');
        Schema::dropIfExists('sync_outbox_operations');
        Schema::dropIfExists('sync_batches');
        Schema::dropIfExists('device_sequences');
        Schema::dropIfExists('sales_idempotency_records');
    }
};
