<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCartSlotsAndAuditLogsTable extends Migration
{
    public function up()
    {
        Schema::create('cart_slots', function (Blueprint $table) {
            $table->id();
            $table->string('slot_code')->unique();
            $table->unsignedInteger('slot_no')->nullable();
            $table->enum('status', ['empty', 'occupied', 'decommissioned'])->default('empty');
            $table->foreignId('current_patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('nurses')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('occupied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('slot_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_log_id')->unique();
            $table->foreignId('slot_id')->constrained('cart_slots');
            $table->foreignId('staff_id')->nullable()->constrained('nurses')->nullOnDelete();
            $table->enum('action', ['create', 'assign', 'update', 'discharge', 'reject']);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['slot_id', 'occurred_at']);
            $table->index(['staff_id', 'occurred_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('slot_audit_logs');
        Schema::dropIfExists('cart_slots');
    }
}
