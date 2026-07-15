<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kds_stations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->unsignedInteger('sla_seconds')->nullable();
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'code']);
            $table->index(['tenant_id', 'branch_id', 'status']);
        });

        Schema::create('kds_tickets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('kds_station_id')->constrained()->restrictOnDelete();
            $table->string('number', 64);
            $table->string('state', 32)->default('queued');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'number']);
            $table->index(['tenant_id', 'branch_id', 'kds_station_id', 'state', 'priority', 'created_at'], 'ix_kds_tickets_queue');
        });

        Schema::create('kds_ticket_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('kds_ticket_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->json('preparation_snapshot')->nullable();
            $table->string('state', 32)->default('queued');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'kds_ticket_id', 'order_item_id'], 'uq_kds_ticket_items_order_item');
            $table->index(['tenant_id', 'branch_id', 'kds_ticket_id', 'state']);
        });

        Schema::create('kds_ticket_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('kds_ticket_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('kds_ticket_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('event_type', 64);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->string('client_operation_id', 128);
            $table->timestamp('occurred_at');
            $table->unique(['tenant_id', 'branch_id', 'kds_ticket_id', 'sequence'], 'uq_kds_ticket_events_sequence');
            $table->unique(['tenant_id', 'branch_id', 'client_operation_id'], 'uq_kds_ticket_events_operation');
        });

        Schema::create('printers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('connection_type', 32);
            $table->json('connection_config')->nullable();
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'code']);
            $table->index(['tenant_id', 'branch_id', 'status']);
        });

        Schema::create('print_routes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('printer_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('kds_station_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('payload_type', 32);
            $table->string('source_type', 64)->nullable();
            $table->ulid('source_id')->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'branch_id', 'payload_type', 'is_active', 'priority'], 'ix_print_routes_payload');
        });

        Schema::create('print_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('printer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('print_route_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('payload_type', 32);
            $table->string('document_type', 64)->nullable();
            $table->ulid('document_id')->nullable();
            $table->json('payload');
            $table->string('payload_hash', 128);
            $table->string('state', 24)->default('pending');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('idempotency_key', 128);
            $table->string('client_operation_id', 128)->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'idempotency_key'], 'uq_print_jobs_idempotency');
            $table->unique(['tenant_id', 'branch_id', 'client_operation_id'], 'uq_print_jobs_client_operation');
            $table->index(['tenant_id', 'branch_id', 'state', 'available_at', 'priority'], 'ix_print_jobs_queue');
        });

        Schema::create('print_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('print_job_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('printer_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('result', 32);
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unique(['tenant_id', 'branch_id', 'print_job_id', 'attempt_number'], 'uq_print_attempts_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_attempts');
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('print_routes');
        Schema::dropIfExists('printers');
        Schema::dropIfExists('kds_ticket_events');
        Schema::dropIfExists('kds_ticket_items');
        Schema::dropIfExists('kds_tickets');
        Schema::dropIfExists('kds_stations');
    }
};
