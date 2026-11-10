<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data kepegawaian dosen dari "Daftar Dosen Jurusan Teknik Informatika dan Komputer":
 * pangkat, golongan, jabatan fungsional, dan TMT-nya. Kosong (NULL) bila belum diketahui (B16).
 * Kolom NIP dibuat oleh migrasi 2026_11_04_130845_support_jtik_spreadsheet_source_data.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('lecturers', function (Blueprint $table) {
            $table->string('rank', 60)->nullable()->after('homebase');
            $table->string('grade', 10)->nullable()->after('rank');
            $table->date('grade_since')->nullable()->after('grade');
            $table->string('functional_position', 60)->nullable()->after('grade_since');
            $table->date('functional_position_since')->nullable()->after('functional_position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lecturers', function (Blueprint $table) {
            $table->dropColumn(['rank', 'grade', 'grade_since', 'functional_position', 'functional_position_since']);
        });
    }
};
