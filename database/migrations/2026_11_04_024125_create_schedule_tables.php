<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jadwal berversi, sesi, peserta, pengajar, masalah draf, dan proses susun otomatis (Bagian 5.4).
     */
    public function up(): void
    {
        Schema::create('schedule_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained();
            $table->unsignedInteger('version_no');
            $table->enum('status', ['draft', 'ready', 'official', 'superseded', 'archived'])->default('draft');
            $table->enum('source', ['manual', 'import', 'generated'])->default('manual');
            $table->foreignId('based_on_version_id')->nullable()->constrained('schedule_versions')->nullOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('published_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['period_id', 'version_no']);
            $table->index(['period_id', 'status']);
        });

        Schema::create('schedule_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_offering_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->enum('mode', ['offline', 'online', 'unscheduled'])->default('offline');
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedSmallInteger('start_minute')->nullable();
            $table->unsignedSmallInteger('end_minute')->nullable();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('phase', ['full', 'before_uts', 'after_uts'])->default('full');
            $table->string('source_file')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->json('source_raw')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            $table->index(['schedule_version_id', 'day_of_week']);
        });

        Schema::create('session_participants', function (Blueprint $table) {
            $table->foreignId('schedule_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();

            $table->primary(['schedule_session_id', 'class_group_id']);
        });

        Schema::create('session_lecturers', function (Blueprint $table) {
            $table->foreignId('schedule_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lecturer_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['lead', 'partner'])->default('lead');

            $table->primary(['schedule_session_id', 'lecturer_id']);
        });

        Schema::create('schedule_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_version_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->enum('severity', ['error', 'warning']);
            $table->json('session_ids');
            $table->text('message');
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['schedule_version_id', 'severity']);
        });

        Schema::create('optimization_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_version_id')->nullable()->constrained()->nullOnDelete();
            $table->json('scope');
            $table->unsignedSmallInteger('time_limit_seconds');
            $table->enum('status', ['queued', 'running', 'finished', 'failed', 'cancelled'])->default('queued');
            $table->enum('solver_status', ['OPTIMAL', 'FEASIBLE', 'INFEASIBLE', 'UNKNOWN', 'MODEL_INVALID'])->nullable();
            $table->string('input_hash', 64);
            $table->json('result')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('optimization_jobs');
        Schema::dropIfExists('schedule_issues');
        Schema::dropIfExists('session_lecturers');
        Schema::dropIfExists('session_participants');
        Schema::dropIfExists('schedule_sessions');
        Schema::dropIfExists('schedule_versions');
    }
};
