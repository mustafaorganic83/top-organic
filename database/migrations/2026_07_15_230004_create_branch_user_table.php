<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch grants: a user may be granted one or many branches; chain roles see
 * aggregates across their granted branches (architecture doc 01 FR-1 / doc 03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');
    }
};
