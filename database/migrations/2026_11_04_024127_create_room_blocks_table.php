<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pemblokiran ruang terpadu — satu-satunya sumber pengecekan bentrok (Bagian 5.5).
     */
    public function up(): void
    {
        Schema::create('room_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->enum('source_type', ['schedule', 'reservation', 'closure']);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('schedule_version_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['room_id', 'start_at', 'end_at', 'is_active']);
            $table->index(['source_type', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_blocks');
    }
};
