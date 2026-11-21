<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('schedule_sessions', function (Blueprint $table): void {
            $table->index(['schedule_version_id', 'room_id', 'day_of_week', 'start_minute'], 'sched_sessions_ver_room_day_start_idx');
        });

        Schema::table('session_participants', function (Blueprint $table): void {
            $table->index(['class_group_id', 'schedule_session_id'], 'session_participants_class_session_idx');
        });

        Schema::table('session_lecturers', function (Blueprint $table): void {
            $table->index(['lecturer_id', 'schedule_session_id'], 'session_lecturers_lecturer_session_idx');
        });

        Schema::table('reservations', function (Blueprint $table): void {
            $table->index(['status', 'start_at', 'end_at'], 'reservations_status_start_end_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropIndex('reservations_status_start_end_idx');
        });

        Schema::table('session_lecturers', function (Blueprint $table): void {
            $table->dropIndex('session_lecturers_lecturer_session_idx');
        });

        Schema::table('session_participants', function (Blueprint $table): void {
            $table->dropIndex('session_participants_class_session_idx');
        });

        Schema::table('schedule_sessions', function (Blueprint $table): void {
            $table->dropIndex('sched_sessions_ver_room_day_start_idx');
        });
    }
};
