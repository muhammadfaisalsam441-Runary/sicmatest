<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data kepegawaian dosen dari "Daftar Dosen Jurusan Teknik Informatika dan Komputer":
 * NIP, pangkat, golongan, jabatan fungsional, dan TMT-nya. Kosong (NULL) bila belum diketahui (B16).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('lecturers', function (Blueprint $table) {
            $table->string('nip', 18)->nullable()->unique()->after('nidn');
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
            $table->dropUnique(['nip']);
            $table->dropColumn(['nip', 'rank', 'grade', 'grade_since', 'functional_position', 'functional_position_since']);
        });
    }
};
