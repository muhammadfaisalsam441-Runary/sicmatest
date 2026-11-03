<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dosen, masa jabatan pimpinan, keahlian, ketersediaan, target beban, dan pembagian mengajar (Bagian 5.3).
     */
    public function up(): void
    {
        Schema::create('lecturers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('nidn', 20)->nullable()->unique();
            $table->enum('homebase', ['jtik', 'external'])->nullable();
            $table->enum('profile_status', ['unverified', 'verified'])->default('unverified');
            $table->boolean('is_active')->default(true);
            $table->string('source_name')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('leadership_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lecturer_id')->constrained()->cascadeOnDelete();
            $table->string('position');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();
        });

        Schema::create('lecturer_expertises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lecturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('field')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('lecturer_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lecturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->unsignedSmallInteger('start_minute');
            $table->unsignedSmallInteger('end_minute');
            $table->enum('type', ['available', 'unavailable']);
            $table->timestamps();
        });

        Schema::create('lecturer_load_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lecturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->decimal('target_sks', 5, 2)->nullable();
            $table->decimal('min_sks', 5, 2)->nullable();
            $table->decimal('max_sks', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['lecturer_id', 'period_id']);
        });

        Schema::create('teaching_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lecturer_id')->constrained();
            $table->enum('role', ['lead', 'partner'])->default('lead');
            $table->decimal('credited_sks', 5, 2)->nullable();
            $table->enum('phase', ['full', 'before_uts', 'after_uts'])->default('full');
            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            $table->unique(['course_offering_id', 'lecturer_id', 'phase']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teaching_assignments');
        Schema::dropIfExists('lecturer_load_targets');
        Schema::dropIfExists('lecturer_availabilities');
        Schema::dropIfExists('lecturer_expertises');
        Schema::dropIfExists('leadership_terms');
        Schema::dropIfExists('lecturers');
    }
};
