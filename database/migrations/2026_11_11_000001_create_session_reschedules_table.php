<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemindahan satu pertemuan kuliah oleh Keti ke slot lain (reservasi "Kuliah Pengganti").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_reschedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('course_offering_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_title');
            $table->string('participant_key');
            $table->dateTime('original_start_at');
            $table->dateTime('original_end_at');
            $table->foreignId('original_room_id')->constrained('rooms');
            $table->foreignId('reservation_id')->constrained();
            $table->enum('status', ['active', 'cancelled', 'replaced'])->default('active');
            $table->foreignId('created_by')->constrained('users');
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['original_start_at', 'status']);
            $table->index(['course_offering_id', 'original_start_at']);
        });

        Schema::create('session_reschedule_lecturers', function (Blueprint $table) {
            $table->foreignId('session_reschedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lecturer_id')->constrained();
            $table->enum('role', ['lead', 'partner'])->default('lead');

            $table->primary(['session_reschedule_id', 'lecturer_id']);
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('session_reschedule_id')->nullable()->after('purpose')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('session_reschedule_id');
        });

        Schema::dropIfExists('session_reschedule_lecturers');
        Schema::dropIfExists('session_reschedules');
    }
};
