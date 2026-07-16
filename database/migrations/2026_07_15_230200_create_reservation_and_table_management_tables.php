<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reservation & Table Management module (architecture doc 01 FR-4). Builds on
 * the floors/dining_tables/table_sessions created with the Sales module: adds
 * indoor/outdoor + VIP/private-room classification and a live occupancy status
 * to dining tables, then introduces rooms, reservation sources, reservations,
 * the waiting list, and an append-only audit trail. ULID keys, tenant/branch
 * scoping, soft deletes, and optimistic lock_version follow existing tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('floor_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('kind', 24)->default('standard'); // standard, vip, private
            $table->unsignedSmallInteger('capacity')->default(0);
            $table->unsignedBigInteger('minimum_spend_amount')->nullable();
            $table->string('currency', 3)->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->text('description')->nullable();
            $table->string('status', 24)->default('active'); // active, inactive
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'code']);
            $table->index(['tenant_id', 'branch_id', 'status']);
        });

        Schema::table('dining_tables', function (Blueprint $table): void {
            $table->foreignUlid('room_id')->nullable()->after('floor_id')->constrained()->nullOnDelete();
            $table->string('area', 24)->default('indoor')->after('name'); // indoor, outdoor
            $table->string('shape', 24)->default('square')->after('area'); // square, round, rectangle
            $table->boolean('is_reservable')->default(true)->after('capacity');
            // Live front-of-house occupancy independent of the soft-delete/active
            // lifecycle already held in `status`.
            $table->string('occupancy_status', 24)->default('available')->after('is_reservable');
            // available, reserved, occupied, held, blocked, cleaning
            $table->index(['tenant_id', 'branch_id', 'occupancy_status'], 'ix_dining_tables_occupancy');
        });

        Schema::create('reservation_sources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('channel', 32); // walk_in, phone, call_center, online, whatsapp, ai, pos
            $table->boolean('auto_confirm')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'channel', 'is_active']);
        });

        Schema::create('reservations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('reservation_source_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('dining_table_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('table_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference', 64);
            $table->string('channel', 32)->default('walk_in');
            $table->string('guest_name');
            $table->string('guest_phone', 32)->nullable();
            $table->string('guest_email')->nullable();
            $table->unsignedSmallInteger('party_size');
            $table->string('area', 24)->nullable(); // indoor, outdoor, any
            $table->timestamp('reserved_for');
            $table->unsignedSmallInteger('duration_minutes')->default(90);
            $table->string('state', 24)->default('pending');
            // pending, confirmed, seated, completed, cancelled, no_show
            $table->boolean('is_walk_in')->default(false);
            $table->text('special_requests')->nullable();
            $table->json('customer_snapshot')->nullable();
            $table->string('confirmation_channel', 32)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('seated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('seated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('client_operation_id', 128)->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'reference']);
            $table->unique(['tenant_id', 'branch_id', 'client_operation_id'], 'uq_reservations_operation');
            $table->index(['tenant_id', 'branch_id', 'state', 'reserved_for'], 'ix_reservations_state_time');
            $table->index(['tenant_id', 'branch_id', 'dining_table_id', 'state'], 'ix_reservations_table_state');
            $table->index(['tenant_id', 'branch_id', 'customer_id']);
        });

        Schema::create('reservation_waitlist_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('reservation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('dining_table_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('guest_name');
            $table->string('guest_phone', 32)->nullable();
            $table->unsignedSmallInteger('party_size');
            $table->string('area', 24)->nullable();
            $table->unsignedInteger('position');
            $table->unsignedSmallInteger('quoted_wait_minutes')->nullable();
            $table->string('state', 24)->default('waiting');
            // waiting, notified, seated, cancelled, expired
            $table->text('notes')->nullable();
            $table->timestamp('joined_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('seated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->index(['tenant_id', 'branch_id', 'state', 'position'], 'ix_waitlist_state_position');
        });

        Schema::create('reservation_audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('reservation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('entity_type', 48)->default('reservation');
            $table->ulid('entity_id');
            $table->string('action', 48);
            $table->string('from_state', 24)->nullable();
            $table->string('to_state', 24)->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->index(['tenant_id', 'branch_id', 'reservation_id'], 'ix_reservation_audit_reservation');
            $table->index(['tenant_id', 'branch_id', 'entity_type', 'entity_id'], 'ix_reservation_audit_entity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_audit_logs');
        Schema::dropIfExists('reservation_waitlist_entries');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('reservation_sources');
        Schema::table('dining_tables', function (Blueprint $table): void {
            $table->dropIndex('ix_dining_tables_occupancy');
            $table->dropConstrainedForeignId('room_id');
            $table->dropColumn(['area', 'shape', 'is_reservable', 'occupancy_status']);
        });
        Schema::dropIfExists('rooms');
    }
};
