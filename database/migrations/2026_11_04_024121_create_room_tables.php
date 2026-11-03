<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gedung, ruang, fasilitas, penutupan ruang, dan favorit (Bagian 5.2).
     */
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->unsignedTinyInteger('floors_count')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->nullable()->constrained();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->tinyInteger('floor')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->enum('type', ['classroom', 'lab', 'workshop', 'other'])->default('classroom');
            $table->boolean('is_bookable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('facility_room', function (Blueprint $table) {
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();

            $table->primary(['room_id', 'facility_id']);
        });

        Schema::create('room_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('reason');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['room_id', 'start_at']);
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['user_id', 'room_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('room_closures');
        Schema::dropIfExists('facility_room');
        Schema::dropIfExists('facilities');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('buildings');
    }
};
