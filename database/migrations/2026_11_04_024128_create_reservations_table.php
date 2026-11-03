<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reservasi ruang oleh Keti atas nama kelas (Bagian 5.6).
     */
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained();
            $table->foreignId('class_group_id')->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->unsignedSmallInteger('participants')->nullable();
            $table->string('purpose');
            $table->enum('status', ['booked', 'in_use', 'finished', 'cancelled'])->default('booked');
            $table->dateTime('actual_start_at')->nullable();
            $table->dateTime('actual_end_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->string('idempotency_key', 64);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['created_by', 'idempotency_key']);
            $table->index(['room_id', 'start_at']);
            $table->index(['class_group_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
