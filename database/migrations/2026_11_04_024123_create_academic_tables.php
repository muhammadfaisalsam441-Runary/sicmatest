<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Periode, kalender, slot jam, aturan penjadwalan, mata kuliah, dan penawaran (Bagian 5.3).
     */
    public function up(): void
    {
        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('timezone', 40)->default('Asia/Makassar');
            $table->date('uts_start')->nullable();
            $table->date('uts_end')->nullable();
            $table->date('uas_start')->nullable();
            $table->date('uas_end')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('calendar_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('type', ['holiday', 'no_class']);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['period_id', 'date']);
        });

        Schema::create('time_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->string('label', 10);
            $table->unsignedSmallInteger('start_minute');
            $table->unsignedSmallInteger('end_minute');
            $table->timestamps();

            $table->unique(['period_id', 'label']);
        });

        Schema::create('scheduling_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->json('value');
            $table->unsignedInteger('version')->default(1);
            $table->date('valid_from')->nullable();
            $table->timestamps();

            $table->unique(['period_id', 'key', 'version']);
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->unsignedTinyInteger('sks');
            $table->string('curriculum')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('course_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained();
            $table->foreignId('course_id')->constrained();
            $table->foreignId('class_group_id')->constrained();
            $table->timestamps();

            $table->unique(['period_id', 'course_id', 'class_group_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_offerings');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('scheduling_rules');
        Schema::dropIfExists('time_slots');
        Schema::dropIfExists('calendar_exceptions');
        Schema::dropIfExists('periods');
    }
};
