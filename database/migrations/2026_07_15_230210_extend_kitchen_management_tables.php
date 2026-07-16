<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kitchen Management System (architecture doc 01 FR-5). Extends the existing
 * kds_* tables — created with the Sales module — with the fields the kitchen
 * board needs: station type/screen wiring, per-station prep SLA, chef
 * assignment, the served phase, and cached prep timing for analytics. No new
 * ticket/station tables are created; the Kitchen module reuses the Sales KDS
 * aggregate so the POS → kitchen flow stays a single source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kds_stations', function (Blueprint $table): void {
            $table->string('station_type', 32)->default('kitchen')->after('name');
            $table->unsignedInteger('default_prep_seconds')->nullable()->after('sla_seconds');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('default_prep_seconds');
            $table->json('screen_config')->nullable()->after('sort_order');
        });

        Schema::table('kds_tickets', function (Blueprint $table): void {
            $table->foreignId('chef_id')->nullable()->after('kds_station_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('cleared_at');
            $table->timestamp('served_at')->nullable()->after('assigned_at');
            $table->unsignedInteger('sla_seconds')->nullable()->after('served_at');
            $table->unsignedInteger('prep_seconds')->nullable()->after('sla_seconds');
            $table->boolean('is_priority')->default(false)->after('priority');
            $table->index(['tenant_id', 'branch_id', 'chef_id', 'state'], 'ix_kds_tickets_chef');
        });

        Schema::table('kds_ticket_items', function (Blueprint $table): void {
            $table->unsignedInteger('prep_seconds')->nullable()->after('state');
            $table->timestamp('started_at')->nullable()->after('prep_seconds');
            $table->timestamp('ready_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('kds_ticket_items', function (Blueprint $table): void {
            $table->dropColumn(['prep_seconds', 'started_at', 'ready_at']);
        });

        Schema::table('kds_tickets', function (Blueprint $table): void {
            $table->dropIndex('ix_kds_tickets_chef');
            $table->dropConstrainedForeignId('chef_id');
            $table->dropColumn(['assigned_at', 'served_at', 'sla_seconds', 'prep_seconds', 'is_priority']);
        });

        Schema::table('kds_stations', function (Blueprint $table): void {
            $table->dropColumn(['station_type', 'default_prep_seconds', 'sort_order', 'screen_config']);
        });
    }
};
