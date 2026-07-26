<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('entity_type', 32);
            $table->string('entity_id', 36); // ULID
            $table->timestamp('as_of_date');
            $table->string('method', 32);
            $table->unsignedBigInteger('unit_cost')->default(0); // minor units
            $table->string('currency_id')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
            
            $table->index(['entity_type', 'entity_id', 'method', 'as_of_date'], 'ix_cost_snapshots_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_snapshots');
    }
};
