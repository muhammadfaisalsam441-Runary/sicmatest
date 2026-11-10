<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('periods', function (Blueprint $table): void {
            $table->date('start_date')->nullable()->change();
            $table->date('end_date')->nullable()->change();
        });

        Schema::table('class_groups', function (Blueprint $table): void {
            $table->unsignedSmallInteger('cohort_year')->nullable()->change();
        });

        Schema::table('lecturers', function (Blueprint $table): void {
            $table->string('nip', 30)->nullable()->unique();
            $table->json('source_data')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('periods')->whereNull('start_date')->orWhereNull('end_date')->exists()
            || DB::table('class_groups')->whereNull('cohort_year')->exists()) {
            throw new LogicException('Lengkapi kalender semester dan angkatan kelas sebelum rollback; nilai kosong tidak diganti dengan data perkiraan.');
        }

        Schema::table('lecturers', function (Blueprint $table): void {
            $table->dropUnique(['nip']);
            $table->dropColumn(['nip', 'source_data']);
        });

        Schema::table('class_groups', function (Blueprint $table): void {
            $table->unsignedSmallInteger('cohort_year')->nullable(false)->change();
        });

        Schema::table('periods', function (Blueprint $table): void {
            $table->date('start_date')->nullable(false)->change();
            $table->date('end_date')->nullable(false)->change();
        });
    }
};
